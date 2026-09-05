<?php

declare(strict_types=1);

namespace App\Services\Ai\Routing;

use App\Data\Ai\Routing\PreferenceResolution;
use App\Models\AiProvider;
use App\Services\Ai\Catalog\CatalogCache;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsRepository;
use App\Support\Audit\AuditActions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The ONE place that decides the preferred provider (Phase C4). Replaces the
 * three former reads of config('ai.provider'):
 *
 *  mode `env` → AI_PROVIDER (config('ai.provider')) — unchanged behaviour;
 *  mode `db`  → the enabled ai_providers row with is_primary = true.
 *               No such row (deleted, disabled, cleared by hand) ⇒ EMERGENCY
 *               fallback to AI_PROVIDER, flagged DEGRADED: warning log,
 *               rate-limited system audit, banner on the routing pages. The
 *               stored mode is never changed automatically.
 *
 * `ai.routing.mode` is an emergency setting (AI_ROUTING_MODE > DB > config)
 * written only by RoutingCutover.
 */
class RoutingPreference
{
    public const MODE_ENV = 'env';

    public const MODE_DB = 'db';

    public const FALLBACK_AUDIT_WINDOW = 900;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function mode(): string
    {
        $mode = strtolower(trim((string) $this->settings->get('ai.routing.mode')));

        return $mode === self::MODE_DB ? self::MODE_DB : self::MODE_ENV;
    }

    public function envProvider(): string
    {
        return (string) config('ai.provider', 'groq');
    }

    public function preferredProvider(): string
    {
        return $this->resolve()->provider;
    }

    public function resolve(): PreferenceResolution
    {
        $mode = $this->mode();

        if ($mode === self::MODE_ENV) {
            return new PreferenceResolution(self::MODE_ENV, $this->envProvider(), 'env');
        }

        $primary = $this->primary();

        if ($primary !== null && $primary->is_enabled) {
            return new PreferenceResolution(self::MODE_DB, (string) $primary->key, 'db', $primary->id);
        }

        $reason = $primary === null ? 'no_primary' : 'primary_disabled';
        $this->signalFallback($reason, $primary);

        return new PreferenceResolution(self::MODE_DB, $this->envProvider(), 'env_fallback', $primary?->id, $reason);
    }

    /**
     * The is_primary row (enabled or not), cached with the catalog version.
     */
    public function primary(): ?AiProvider
    {
        return CatalogCache::remember('primary', static function (): ?AiProvider {
            try {
                if (! Schema::hasTable('ai_providers')) {
                    return null;
                }

                return AiProvider::query()->where('is_primary', true)->first();
            } catch (Throwable) {
                return null;
            }
        });
    }

    private function signalFallback(string $reason, ?AiProvider $primary): void
    {
        Log::warning('sanad.routing.env_fallback', ['reason' => $reason, 'provider' => $this->envProvider()]);

        try {
            if (! Cache::add('sanad.routing.env_fallback_audited.'.$reason, 1, self::FALLBACK_AUDIT_WINDOW)) {
                return;
            }

            $this->audit->record(AuditActions::AiRoutingEnvFallbackUsed, $primary, [], [
                'reason' => $reason,
                'fallback_provider' => $this->envProvider(),
                'stored_mode' => self::MODE_DB,
            ]);
        } catch (Throwable $e) {
            Log::warning('sanad.routing.env_fallback_audit_unavailable', ['error' => $e::class]);
        }
    }
}
