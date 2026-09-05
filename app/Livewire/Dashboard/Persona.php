<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Exceptions\Settings\InvalidSettingValueException;
use App\Services\Settings\SettingsRepository;
use App\Support\Rbac\Permission;
use App\Support\Settings\PromptTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Persona + prompt templates (Phase C1, permission persona.manage). Plain text
 * editors with a live preview; templates are validated against their
 * placeholder allowlist by SettingsRepository — nothing here executes text.
 */
#[Title('شخصية سَنَد | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Persona extends Component
{
    public string $persona = '';

    public string $temporal = '';

    public ?string $notice = null;

    public ?string $personaError = null;

    public ?string $temporalError = null;

    public function mount(SettingsRepository $settings): void
    {
        abort_unless(auth()->user()?->can(Permission::PersonaManage->value) ?? false, 403);

        $this->loadState($settings);
    }

    public function savePersona(SettingsRepository $settings): void
    {
        $this->notice = null;
        $this->personaError = null;

        try {
            $settings->set('ai.persona', $this->persona, 'sanad admin');
            $this->notice = 'تم حفظ الشخصية.';
        } catch (InvalidSettingValueException $e) {
            $this->personaError = implode(' ', $e->errors);
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->loadState($settings);
    }

    public function saveTemporal(SettingsRepository $settings): void
    {
        $this->notice = null;
        $this->temporalError = null;

        try {
            $settings->set('prompts.temporal_context', $this->temporal, 'sanad admin');
            $this->notice = 'تم حفظ قالب سياق الوقت.';
        } catch (InvalidSettingValueException $e) {
            $this->temporalError = implode(' ', $e->errors);
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->loadState($settings);
    }

    public function resetKey(string $key, SettingsRepository $settings): void
    {
        $this->notice = null;

        try {
            $settings->reset($key, 'sanad admin');
            $this->notice = 'أُعيد إلى القيمة الافتراضية.';
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->loadState($settings);
    }

    public function render(SettingsRepository $settings)
    {
        $timezone = (string) config('sanad.default_user_timezone', 'Asia/Hebron');

        return view('livewire.dashboard.persona', [
            'personaEffective' => $settings->effective('ai.persona'),
            'temporalEffective' => $settings->effective('prompts.temporal_context'),
            'temporalPreview' => PromptTemplate::render($this->temporal, [
                'timezone' => $timezone,
                'now' => CarbonImmutable::now($timezone)->translatedFormat('l، j F Y - H:i'),
            ]),
            'personaLength' => mb_strlen($this->persona),
        ]);
    }

    private function loadState(SettingsRepository $settings): void
    {
        $this->persona = (string) $settings->get('ai.persona');
        $this->temporal = (string) $settings->get('prompts.temporal_context');
    }
}
