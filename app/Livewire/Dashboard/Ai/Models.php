<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Ai;

use App\Enums\AiOperation;
use App\Livewire\Dashboard\Ai\Concerns\HandlesCatalogWrites;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\Ai\Catalog\CatalogAdmin;
use App\Support\Rbac\Permission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Catalog models (Phase C2): create / edit / delete through CatalogAdmin only
 * (`ai.models.manage`). Enable/disable and priority changes go through the
 * routing simulation: a change that would leave `chat` without a route is
 * refused; one that would change the selected route needs the typed
 * confirmation. Deletion is allowed only for a disabled, unreferenced model.
 */
#[Title('النماذج | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Models extends Component
{
    use HandlesCatalogWrites;

    /** null = closed, 0 = create, >0 = editing that id */
    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::AiModelsManage->value) ?? false, 403);
    }

    public function create(): void
    {
        $this->editing = 0;
        $this->form = self::blankForm();
        $this->cancelConfirmation();
        $this->notice = null;
    }

    public function edit(int $id): void
    {
        $model = AiModel::query()->findOrFail($id);

        $this->editing = $model->id;
        $this->form = [
            'provider_id' => (string) $model->provider_id,
            'external_id' => (string) $model->external_id,
            'name' => (string) $model->name,
            'aliases' => implode(', ', array_map('strval', (array) ($model->aliases ?? []))),
            'capabilities' => array_values((array) ($model->capabilities ?? ['chat'])),
            'supports_tools' => $model->supports_tools ? '1' : '0',
            'context_window' => $model->context_window === null ? '' : (string) $model->context_window,
            'max_output_tokens' => $model->max_output_tokens === null ? '' : (string) $model->max_output_tokens,
            'priority' => (string) $model->priority,
            'is_enabled' => $model->is_enabled ? '1' : '0',
            'fallback_model_id' => $model->fallback_model_id === null ? '' : (string) $model->fallback_model_id,
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
        $input = [
            'provider_id' => $this->form['provider_id'] ?? null,
            'external_id' => $this->form['external_id'] ?? '',
            'name' => $this->form['name'] ?? '',
            'aliases' => (string) ($this->form['aliases'] ?? ''),
            'capabilities' => $this->form['capabilities'] ?? [],
            'supports_tools' => ($this->form['supports_tools'] ?? '0') === '1',
            'context_window' => $this->form['context_window'] ?? null,
            'max_output_tokens' => $this->form['max_output_tokens'] ?? null,
            'priority' => $this->form['priority'] ?? 0,
            'is_enabled' => ($this->form['is_enabled'] ?? '0') === '1',
            'fallback_model_id' => $this->form['fallback_model_id'] ?? null,
        ];

        $saved = $this->attemptCatalogWrite(function (?string $confirmation) use ($admin, $input): string {
            if ((int) $this->editing === 0) {
                $model = $admin->createModel($input, $confirmation);

                return 'أُنشئ النموذج «'.$model->handle().'».';
            }

            $model = $admin->updateModel(AiModel::query()->findOrFail((int) $this->editing), $input, $confirmation);

            return 'تم حفظ النموذج «'.$model->handle().'».';
        });

        if ($saved) {
            $this->editing = null;
            $this->form = [];
        }
    }

    public function delete(int $id, CatalogAdmin $admin): void
    {
        $model = AiModel::query()->findOrFail($id);
        $handle = $model->handle();

        $this->attemptCatalogWrite(function () use ($admin, $model, $handle): string {
            $admin->deleteModel($model);

            return 'حُذف النموذج «'.$handle.'».';
        });
    }

    public function render()
    {
        $providers = AiProvider::query()->orderByDesc('priority')->orderBy('id')->get();
        $models = AiModel::query()->with(['provider', 'fallback.provider'])->withCount('prices')->orderByDesc('priority')->orderBy('id')->get();

        return view('livewire.dashboard.ai.models', [
            'providers' => $providers,
            'grouped' => $models->groupBy('provider_id'),
            'allModels' => $models,
            'operations' => AiOperation::cases(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function blankForm(): array
    {
        return [
            'provider_id' => '', 'external_id' => '', 'name' => '', 'aliases' => '', 'capabilities' => ['chat'],
            'supports_tools' => '1', 'context_window' => '', 'max_output_tokens' => '', 'priority' => '0',
            'is_enabled' => '0', 'fallback_model_id' => '',
        ];
    }
}
