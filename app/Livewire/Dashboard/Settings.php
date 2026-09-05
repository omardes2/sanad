<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Data\Settings\EffectiveSetting;
use App\Enums\SettingType;
use App\Exceptions\Settings\InvalidSettingValueException;
use App\Exceptions\Settings\ReadOnlySettingException;
use App\Services\Settings\SettingsRepository;
use App\Support\Rbac\Permission;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * App settings (Phase C1): every registered setting except the persona group,
 * with its EFFECTIVE value and source (env / db / default). The page only
 * offers an editor when the current user holds the setting's own permission
 * and the value is editable; SettingsRepository enforces the same rules
 * server-side regardless of what the UI shows.
 */
#[Title('الإعدادات | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Settings extends Component
{
    /**
     * Form values keyed by the setting's FORM key (dots replaced by "__"):
     * Livewire treats a dot in a property path as nesting, so `ai.temperature`
     * must not be used as an array key on the wire.
     *
     * @var array<string, mixed>
     */
    public array $values = [];

    /** @var array<string, string> per-key error messages */
    public array $messages = [];

    public ?string $notice = null;

    public function mount(SettingsRepository $settings): void
    {
        abort_unless(auth()->user()?->can(Permission::SettingsManage->value) ?? false, 403);

        $this->loadValues($settings);
    }

    public function save(string $key, SettingsRepository $settings): void
    {
        $this->notice = null;
        unset($this->messages[$key]);

        try {
            $effective = $settings->set($key, $this->values[self::formKey($key)] ?? null, 'sanad admin');
            $this->notice = 'تم حفظ «'.$effective->definition->label.'».';
        } catch (InvalidSettingValueException $e) {
            $this->messages[$key] = implode(' ', $e->errors);
        } catch (ReadOnlySettingException) {
            $this->messages[$key] = 'هذا الإعداد للعرض فقط في هذه المرحلة.';
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->loadValues($settings);
    }

    public function resetToDefault(string $key, SettingsRepository $settings): void
    {
        $this->notice = null;
        unset($this->messages[$key]);

        try {
            $effective = $settings->reset($key, 'sanad admin');
            $this->notice = 'أُعيد «'.$effective->definition->label.'» إلى القيمة الافتراضية.';
        } catch (ReadOnlySettingException) {
            $this->messages[$key] = 'هذا الإعداد للعرض فقط في هذه المرحلة.';
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->loadValues($settings);
    }

    public function render(SettingsRepository $settings)
    {
        $user = auth()->user();
        $groups = [];

        foreach ($settings->allEffective() as $effective) {
            if ($effective->definition->group === SettingsRegistry::GROUP_PERSONA) {
                continue; // its own page (persona.manage)
            }

            $groups[$effective->definition->group][] = [
                'effective' => $effective,
                'canEdit' => $effective->editable() && ($user?->can($effective->definition->permission->value) ?? false),
            ];
        }

        return view('livewire.dashboard.settings', [
            'groups' => $groups,
            'labels' => SettingsRegistry::groupLabels(),
        ]);
    }

    private function loadValues(SettingsRepository $settings): void
    {
        foreach ($settings->allEffective() as $key => $effective) {
            $this->values[self::formKey($key)] = $this->formValue($effective);
        }
    }

    public static function formKey(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    private function formValue(EffectiveSetting $effective): mixed
    {
        $value = $effective->value;

        return match ($effective->definition->type) {
            SettingType::Boolean => $value ? '1' : '0',
            default => $value === null ? '' : (string) $value,
        };
    }
}
