<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Ai;

use App\Enums\AiOperation;
use App\Livewire\Dashboard\Ai\Concerns\HandlesCatalogWrites;
use App\Models\AiProvider;
use App\Services\Ai\AiManager;
use App\Services\Ai\Catalog\CatalogAdmin;
use App\Services\Ai\Catalog\CatalogSourceResolver;
use App\Support\Rbac\Permission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * AI providers as operational data (Phase C2). `ai.providers.view` opens the
 * page; editing needs `ai.providers.manage` (enforced again by CatalogAdmin).
 *
 * Deliberately NOT here: credentials (only "configured: yes/no" from the
 * environment, never a value or fingerprint), Test Connection, health,
 * is_primary (read-only until the C4 cutover), base_url application (stored
 * only — adapters keep reading config until C3).
 */
#[Title('مزوّدو الذكاء الاصطناعي | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Providers extends Component
{
    use HandlesCatalogWrites;

    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::AiProvidersView->value) ?? false, 403);
    }

    public function edit(int $id): void
    {
        abort_unless(auth()->user()?->can(Permission::AiProvidersManage->value) ?? false, 403);

        $provider = AiProvider::query()->findOrFail($id);

        $this->editing = $provider->id;
        $this->form = [
            'name' => (string) $provider->name,
            'base_url' => (string) ($provider->base_url ?? ''),
            'priority' => (string) $provider->priority,
            'is_enabled' => $provider->is_enabled ? '1' : '0',
            'capabilities' => array_values((array) ($provider->capabilities ?? [])),
        ];
        $this->cancelConfirmation();
        $this->notice = null;
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->form = [];
        $this->cancelConfirmation();
    }

    public function save(CatalogAdmin $admin): void
    {
        $provider = AiProvider::query()->findOrFail((int) $this->editing);

        $saved = $this->attemptCatalogWrite(fn (?string $confirmation): string => 'تم حفظ المزوّد «'.$admin->updateProvider($provider, [
            'name' => $this->form['name'] ?? '',
            'base_url' => $this->form['base_url'] ?? null,
            'priority' => $this->form['priority'] ?? 0,
            'is_enabled' => ($this->form['is_enabled'] ?? '0') === '1',
            'capabilities' => $this->form['capabilities'] ?? [],
        ], $confirmation)->key.'».');

        if ($saved) {
            $this->editing = null;
            $this->form = [];
        }
    }

    public function render(AiManager $manager, CatalogSourceResolver $resolver)
    {
        $user = auth()->user();
        $providers = AiProvider::query()->withCount('models')->orderByDesc('priority')->orderBy('id')->get();

        $rows = $providers->map(static function (AiProvider $provider) use ($manager): array {
            $known = $manager->has($provider->key);

            return [
                'provider' => $provider,
                'driver_known' => $known,
                'configured' => $known ? $manager->provider($provider->key)->isConfigured() : null,
            ];
        });

        return view('livewire.dashboard.ai.providers', [
            'rows' => $rows,
            'canManage' => $user?->can(Permission::AiProvidersManage->value) ?? false,
            'operations' => AiOperation::cases(),
            'preferred' => (string) config('ai.provider', 'groq'),
            'sourceMode' => $resolver->mode(),
            'sourceActive' => $resolver->activeName(),
        ]);
    }
}
