<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Ai;

use App\Models\AiProvider;
use App\Models\ProviderHealthCheck;
use App\Services\Credentials\CredentialResolver;
use App\Services\Credentials\CredentialVault;
use App\Services\Settings\SettingsRepository;
use App\Support\Rbac\Permission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Provider health history (Phase C3): read-only, `ai.health.view`. Shows the
 * latest probe per provider and the filterable history. Never triggers a
 * probe (that is the providers page, behind `ai.credentials.test`) and never
 * reads a secret.
 */
#[Title('صحة المزوّدين | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Health extends Component
{
    use WithPagination;

    #[Url]
    public string $provider = '';

    #[Url]
    public string $kind = '';

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::AiHealthView->value) ?? false, 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['provider', 'kind', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function render(SettingsRepository $settings, CredentialResolver $credentials, CredentialVault $vault)
    {
        $providers = AiProvider::query()->orderByDesc('priority')->orderBy('id')->get();
        $latest = ProviderHealthCheck::query()->orderByDesc('checked_at')->orderByDesc('id')->get()->unique('provider_id')->keyBy('provider_id');

        $history = ProviderHealthCheck::query()
            ->with('provider:id,key')
            ->when($this->provider !== '', fn ($q) => $q->where('provider_id', (int) $this->provider))
            ->when($this->kind !== '', fn ($q) => $q->where('kind', $this->kind))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('checked_at')->orderByDesc('id')
            ->paginate(25);

        return view('livewire.dashboard.ai.health', [
            'providers' => $providers,
            'latest' => $latest,
            'history' => $history,
            'scheduled' => (bool) $settings->get('ai.health.scheduled'),
            'credentialsMode' => $credentials->mode(),
            'vaultAvailable' => $vault->available(),
        ]);
    }
}
