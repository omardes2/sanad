<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog;

use App\Enums\AiOperation;
use App\Exceptions\Ai\CatalogValidationException;
use App\Exceptions\Ai\FallbackCycleException;
use App\Exceptions\Ai\LastViableRouteException;
use App\Exceptions\Ai\RoutingChangeConfirmationRequired;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ModelPrice;
use App\Models\UsageEvent;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Permission;
use App\Support\Security\UrlPolicy;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The ONLY writer of ai_providers / ai_models from the admin UI (Phase C2).
 * Prices stay with PriceBook; bootstrap stays with sanad:ai:bootstrap.
 *
 * Every write follows the same contract:
 *  1. the caller's permission is enforced HERE (server-side), whatever the UI
 *     showed — console runs (operators) are allowed and audited as console;
 *  2. input is validated (CatalogValidationException lists every problem);
 *  3. inside ONE transaction the parent ai_providers row is locked
 *     (SELECT ... FOR UPDATE) before anything is examined, so two concurrent
 *     writers for the same provider are serialised: external_id / alias
 *     uniqueness within the provider is checked under that lock, then the
 *     fallback graph (no cycles, bounded depth), then — for a change to
 *     is_enabled or priority — the routing simulation:
 *       · no `chat` candidate would remain → LastViableRouteException (refused);
 *       · the selected `chat` route would change → the typed confirmation
 *         must equal the NEW route handle, else RoutingChangeConfirmationRequired;
 *  4. the row and its audit entry (before/after + simulation result) are
 *     written in that same transaction — an audit failure rolls the change back;
 *  5. the catalog cache is invalidated AFTER the outermost transaction has
 *     committed (DB::afterCommit — never on a savepoint, never on rollback).
 *     Invalidation failure never fails the write (CatalogCache logs a
 *     warning; TTL expiry bounds the staleness).
 *
 * Out of scope by decision: provider key/driver/credentials_ref (Phase C3),
 * is_primary (read-only until the Phase C4 cutover), base_url is validated by
 * UrlPolicy and STORED ONLY — the adapters keep reading config until C3.
 */
class CatalogAdmin
{
    /** @var list<string> attributes an admin may never touch through this service */
    private const PROVIDER_LOCKED = ['key', 'driver', 'credentials_ref', 'is_primary'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RoutingSimulator $simulator,
    ) {}

    // ---- Providers ----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input  name, base_url, priority, is_enabled, capabilities
     *
     * @throws AuthorizationException|CatalogValidationException|LastViableRouteException|RoutingChangeConfirmationRequired
     */
    public function updateProvider(AiProvider $provider, array $input, ?string $confirmation = null): AiProvider
    {
        $this->authorize(Permission::AiProvidersManage);
        $data = $this->validateProvider($input);

        return $this->transact($provider->id, function (AiProvider $locked) use ($data, $confirmation): AiProvider {
            $locked->fill($data);
            $changes = $this->diff($locked);

            if ($changes === []) {
                return $locked;
            }

            $simulation = null;

            if (isset($changes['is_enabled']) || isset($changes['priority'])) {
                $simulation = $this->simulate(
                    [$locked->id => ['is_enabled' => (bool) $locked->is_enabled, 'priority' => (int) $locked->priority]],
                    [],
                    $locked->key,
                    $confirmation,
                );
            }

            $locked->save();
            $this->audit->record(AuditActions::AiProviderUpdated, $locked, $changes, array_filter([
                'simulation' => $simulation,
                'base_url_applied' => isset($changes['base_url']) ? false : null,
            ], static fn ($v) => $v !== null));

            return $locked;
        });
    }

    // ---- Models -------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input  provider_id, external_id, name, aliases, capabilities, supports_tools, context_window, max_output_tokens, priority, is_enabled, fallback_model_id
     *
     * @throws AuthorizationException|CatalogValidationException|FallbackCycleException|LastViableRouteException|RoutingChangeConfirmationRequired
     */
    public function createModel(array $input, ?string $confirmation = null): AiModel
    {
        $this->authorize(Permission::AiModelsManage);
        $data = $this->validateModel($input, null);
        $providerId = (int) $data['provider_id'];
        unset($data['provider_id']);

        return $this->transact($providerId, function (AiProvider $provider) use ($data, $confirmation): AiModel {
            $this->assertUniqueWithinProvider($provider, null, (string) $data['external_id'], $data['aliases']);

            // Insert disabled and without a fallback, then apply the requested
            // fallback / enable flag exactly like an update — so the cycle check
            // and the routing simulation see the row as the database will.
            $model = AiModel::query()->create(array_merge($data, [
                'provider_id' => $provider->id,
                'is_enabled' => false,
                'fallback_model_id' => null,
            ]));

            $this->assertFallback($model, $data['fallback_model_id'], $provider);
            $model->fallback_model_id = $data['fallback_model_id'];

            $simulation = null;

            if ($data['is_enabled']) {
                $simulation = $this->simulate([], [$model->id => ['is_enabled' => true]], $provider->key.':'.$model->external_id, $confirmation);
                $model->is_enabled = true;
            }

            $model->save();
            $this->audit->record(AuditActions::AiModelCreated, $model, [
                'model' => ['from' => null, 'to' => $this->snapshot($model)],
            ], array_filter(['simulation' => $simulation], static fn ($v) => $v !== null));

            return $model;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException|CatalogValidationException|FallbackCycleException|LastViableRouteException|RoutingChangeConfirmationRequired
     */
    public function updateModel(AiModel $model, array $input, ?string $confirmation = null): AiModel
    {
        $this->authorize(Permission::AiModelsManage);
        $data = $this->validateModel($input, $model);
        unset($data['provider_id']); // a model never moves between providers

        return $this->transact($model->provider_id, function (AiProvider $provider) use ($model, $data, $confirmation): AiModel {
            $locked = AiModel::query()->whereKey($model->id)->firstOrFail();
            $locked->fill($data);
            $changes = $this->diff($locked);

            if ($changes === []) {
                return $locked;
            }

            if (isset($changes['external_id']) || isset($changes['aliases'])) {
                $this->assertUniqueWithinProvider($provider, $locked->id, (string) $locked->external_id, (array) $locked->aliases);
            }

            if (isset($changes['fallback_model_id'])) {
                $this->assertFallback($locked, $locked->fallback_model_id === null ? null : (int) $locked->fallback_model_id, $provider);
            }

            $simulation = null;

            if (isset($changes['is_enabled']) || isset($changes['priority'])) {
                $simulation = $this->simulate(
                    [],
                    [$locked->id => ['is_enabled' => (bool) $locked->is_enabled, 'priority' => (int) $locked->priority]],
                    $provider->key.':'.$locked->external_id,
                    $confirmation,
                );
            }

            $locked->save();
            $this->audit->record(AuditActions::AiModelUpdated, $locked, $changes, array_filter(['simulation' => $simulation], static fn ($v) => $v !== null));

            return $locked;
        });
    }

    /**
     * Deleting is allowed only for a DISABLED model that nothing references:
     * no price history, no other model's fallback, no usage event costed with
     * it. (History is never orphaned; disable instead.)
     *
     * @throws AuthorizationException|CatalogValidationException
     */
    public function deleteModel(AiModel $model): void
    {
        $this->authorize(Permission::AiModelsManage);

        $this->transact($model->provider_id, function (AiProvider $provider) use ($model): void {
            $locked = AiModel::query()->whereKey($model->id)->firstOrFail();
            $handle = $provider->key.':'.$locked->external_id;
            $errors = [];

            if ($locked->is_enabled) {
                $errors[] = "النموذج [{$handle}] مفعّل؛ عطّله أولًا (يمرّ التعطيل بمحاكاة التوجيه).";
            }

            if (ModelPrice::query()->where('model_id', $locked->id)->exists()) {
                $errors[] = "النموذج [{$handle}] له سجل أسعار؛ لا يُحذف تاريخ الأسعار.";
            }

            $dependents = AiModel::query()->where('fallback_model_id', $locked->id)->pluck('external_id')->all();

            if ($dependents !== []) {
                $errors[] = "النموذج [{$handle}] بديل للنماذج: ".implode(', ', $dependents).'.';
            }

            if (UsageEvent::query()->where('ai_model_id', $locked->id)->exists()) {
                $errors[] = "النموذج [{$handle}] مرتبط بأحداث استخدام مسجَّلة؛ عطّله بدل حذفه.";
            }

            if ($errors !== []) {
                throw new CatalogValidationException($errors);
            }

            $snapshot = $this->snapshot($locked);
            $locked->delete();
            $this->audit->record(AuditActions::AiModelDeleted, null, ['model' => ['from' => $snapshot, 'to' => null]], ['model' => $handle]);
        });
    }

    // ---- Internals ----------------------------------------------------------

    /**
     * Lock the parent provider row, run the write, then invalidate the cache
     * only after the commit succeeded.
     *
     * @template T
     *
     * @param  Closure(AiProvider): T  $callback
     * @return T
     */
    private function transact(int $providerId, Closure $callback): mixed
    {
        $result = DB::transaction(static function () use ($providerId, $callback): mixed {
            $provider = AiProvider::query()->whereKey($providerId)->lockForUpdate()->firstOrFail();

            return $callback($provider);
        });

        // After the OUTERMOST commit only (deferred when a caller wraps us in
        // its own transaction; discarded on its rollback). Tolerant: a cache
        // failure is logged, never raised — the TTL bounds the staleness.
        CatalogCache::flushAfterCommit();

        return $result;
    }

    /**
     * @param  array<int, array{is_enabled?: bool, priority?: int}>  $providerOverrides
     * @param  array<int, array{is_enabled?: bool, priority?: int}>  $modelOverrides
     * @return array{before: ?string, after: ?string, confirmed: bool, candidates_after: int}
     *
     * @throws LastViableRouteException|RoutingChangeConfirmationRequired
     */
    private function simulate(array $providerOverrides, array $modelOverrides, string $subject, ?string $confirmation): array
    {
        $before = $this->simulator->proposed();
        $after = $this->simulator->proposed($providerOverrides, $modelOverrides, AiOperation::Chat);

        if (! $after->hasRoute()) {
            throw LastViableRouteException::for($subject);
        }

        $confirmed = false;

        if ($before->selectedHandle() !== $after->selectedHandle()) {
            if ($confirmation === null || trim($confirmation) !== $after->selectedHandle()) {
                throw new RoutingChangeConfirmationRequired($before->selectedHandle(), $after->selectedHandle());
            }

            $confirmed = true;
        }

        return [
            'before' => $before->selectedHandle(),
            'after' => $after->selectedHandle(),
            'confirmed' => $confirmed,
            'candidates_after' => count($after->eligible()),
        ];
    }

    /**
     * external_id and every alias must be unique across the provider's models
     * (an id may designate exactly one model), and must not repeat each other.
     * Called under the provider row lock.
     *
     * @param  list<string>  $aliases
     *
     * @throws CatalogValidationException
     */
    private function assertUniqueWithinProvider(AiProvider $provider, ?int $selfId, string $externalId, array $aliases): void
    {
        $ids = array_values(array_filter(array_map('strval', $aliases), static fn (string $a): bool => $a !== ''));
        $errors = [];

        if (count(array_unique($ids)) !== count($ids) || in_array($externalId, $ids, true)) {
            $errors[] = 'الأسماء البديلة يجب أن تكون فريدة ولا تساوي المعرّف الخارجي.';
        }

        $mine = array_unique([$externalId, ...$ids]);

        $siblings = AiModel::query()
            ->where('provider_id', $provider->id)
            ->when($selfId !== null, static fn ($q) => $q->whereKeyNot($selfId))
            ->orderBy('id')
            ->get(['id', 'external_id', 'aliases']);

        foreach ($siblings as $sibling) {
            $taken = [$sibling->external_id, ...array_map('strval', (array) $sibling->aliases)];

            foreach (array_intersect($mine, $taken) as $clash) {
                $errors[] = "المعرّف [{$clash}] مستخدم بالفعل في النموذج [{$provider->key}:{$sibling->external_id}].";
            }
        }

        if ($errors !== []) {
            throw new CatalogValidationException(array_values(array_unique($errors)));
        }
    }

    /**
     * @throws CatalogValidationException|FallbackCycleException
     */
    private function assertFallback(AiModel $model, ?int $fallbackId, AiProvider $provider): void
    {
        if ($fallbackId === null) {
            return;
        }

        if (! AiModel::query()->whereKey($fallbackId)->exists()) {
            throw new CatalogValidationException(["النموذج البديل [{$fallbackId}] غير موجود."]);
        }

        FallbackGraph::assertAcyclic($model, $fallbackId);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws CatalogValidationException
     */
    private function validateProvider(array $input): array
    {
        $errors = [];

        foreach (self::PROVIDER_LOCKED as $locked) {
            if (array_key_exists($locked, $input)) {
                $errors[] = "الحقل [{$locked}] للقراءة فقط في هذه المرحلة.";
            }
        }

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:191'],
            'base_url' => ['nullable', 'string', 'max:2048'],
            'priority' => ['required', 'integer', 'between:-1000,1000'],
            'is_enabled' => ['required', 'boolean'],
            'capabilities' => ['present', 'array'],
            'capabilities.*' => ['string', 'in:'.implode(',', array_map(static fn (AiOperation $o): string => $o->value, AiOperation::cases()))],
        ]);

        if ($validator->fails()) {
            $errors = [...$errors, ...$validator->errors()->all()];
        }

        $baseUrl = isset($input['base_url']) && trim((string) $input['base_url']) !== '' ? trim((string) $input['base_url']) : null;

        if ($baseUrl !== null) {
            $errors = [...$errors, ...UrlPolicy::check($baseUrl)];
        }

        if ($errors !== []) {
            throw new CatalogValidationException(array_values(array_unique($errors)));
        }

        return [
            'name' => trim((string) $input['name']),
            'base_url' => $baseUrl,
            'priority' => (int) $input['priority'],
            'is_enabled' => filter_var($input['is_enabled'], FILTER_VALIDATE_BOOLEAN),
            'capabilities' => array_values(array_unique(array_map('strval', (array) $input['capabilities']))),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws CatalogValidationException
     */
    private function validateModel(array $input, ?AiModel $existing): array
    {
        $identifier = 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,190}$/';
        $operations = implode(',', array_map(static fn (AiOperation $o): string => $o->value, AiOperation::cases()));

        if (is_string($input['aliases'] ?? null)) {
            $input['aliases'] = preg_split('/[\s,]+/', trim($input['aliases']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $validator = Validator::make($input, [
            'provider_id' => [$existing === null ? 'required' : 'nullable', 'integer', 'exists:ai_providers,id'],
            'external_id' => ['required', 'string', $identifier],
            'name' => ['required', 'string', 'max:191'],
            'aliases' => ['present', 'array', 'max:50'],
            'aliases.*' => ['string', $identifier],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['string', 'in:'.$operations],
            'supports_tools' => ['required', 'boolean'],
            'context_window' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'max_output_tokens' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'priority' => ['required', 'integer', 'between:-1000,1000'],
            'is_enabled' => ['required', 'boolean'],
            'fallback_model_id' => ['nullable', 'integer'],
        ], [], [
            'external_id' => 'external_id', 'aliases.*' => 'alias',
        ]);

        if ($validator->fails()) {
            throw new CatalogValidationException($validator->errors()->all());
        }

        $nullableInt = static fn (mixed $v): ?int => $v === null || $v === '' ? null : (int) $v;

        return [
            'provider_id' => $nullableInt($input['provider_id'] ?? null),
            'external_id' => trim((string) $input['external_id']),
            'name' => trim((string) $input['name']),
            'aliases' => array_values(array_unique(array_map(static fn ($a): string => trim((string) $a), (array) $input['aliases']))),
            'capabilities' => array_values(array_unique(array_map('strval', (array) $input['capabilities']))),
            'supports_tools' => filter_var($input['supports_tools'], FILTER_VALIDATE_BOOLEAN),
            'context_window' => $nullableInt($input['context_window'] ?? null),
            'max_output_tokens' => $nullableInt($input['max_output_tokens'] ?? null),
            'priority' => (int) $input['priority'],
            'is_enabled' => filter_var($input['is_enabled'], FILTER_VALIDATE_BOOLEAN),
            'fallback_model_id' => $nullableInt($input['fallback_model_id'] ?? null),
        ];
    }

    /**
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(Model $model): array
    {
        $changes = [];

        foreach (array_keys($model->getDirty()) as $attribute) {
            $from = $model->getOriginal($attribute);
            $to = $model->getAttribute($attribute);

            // JSON columns: compare the decoded values, not the encoded strings.
            if (json_encode($from) === json_encode($to)) {
                continue;
            }

            $changes[$attribute] = ['from' => $from, 'to' => $to];
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(AiModel $model): array
    {
        return [
            'id' => $model->id,
            'provider_id' => $model->provider_id,
            'external_id' => $model->external_id,
            'name' => $model->name,
            'aliases' => $model->aliases,
            'capabilities' => $model->capabilities,
            'supports_tools' => $model->supports_tools,
            'context_window' => $model->context_window,
            'max_output_tokens' => $model->max_output_tokens,
            'is_enabled' => $model->is_enabled,
            'priority' => $model->priority,
            'fallback_model_id' => $model->fallback_model_id,
        ];
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(Permission $permission): void
    {
        $user = Auth::user();

        if ($user !== null) {
            if (! $user->can($permission->value)) {
                throw new AuthorizationException("Missing permission [{$permission->value}] for the AI catalog.");
            }

            return;
        }

        if (! app()->runningInConsole()) {
            throw new AuthorizationException('Unauthenticated write to the AI catalog.');
        }
    }
}
