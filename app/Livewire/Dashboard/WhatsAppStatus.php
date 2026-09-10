<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Services\Platform\InfrastructureHealth;
use App\Support\WhatsApp\WhatsAppConfig;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Read-only operational view of the WhatsApp integration.
 *
 * PRIVACY: this page exposes ONLY booleans and infra health — never a token,
 * app secret, verify token, or any credential value. Config presence is
 * reported through WhatsAppConfig's boolean capability checks; the raw
 * values are never read or rendered here.
 *
 * The Horizon / Redis / queue-depth probes moved to `InfrastructureHealth` so
 * the readiness board and this page cannot drift apart about what "healthy"
 * means.
 */
#[Title('حالة واتساب | سَنَد')]
#[Layout('components.layouts.dashboard')]
class WhatsAppStatus extends Component
{
    public function render(WhatsAppConfig $config, InfrastructureHealth $health)
    {
        return view('livewire.dashboard.whatsapp-status', [
            'enabled' => $config->enabled(),
            // Presence booleans only — derived from capability checks, no values.
            'checklist' => [
                'رمز الوصول (Access Token)' => $config->canSend(),
                'التوقيع (App Secret)' => $config->canValidateSignature(),
                'رمز التحقق (Verify Token)' => $config->canVerifyWebhook(),
                'معرّف رقم الهاتف' => $config->phoneNumberId !== null,
                'معرّف حساب الأعمال (WABA)' => $config->businessAccountId !== null,
                'إصدار Graph API' => $config->graphVersion !== '',
            ],
            'canSend' => $config->canSend(),
            'canReceive' => $config->enabled() && $config->canValidateSignature(),
            'horizon' => $health->horizon(),
            'redisUp' => $health->redis(),
            'queues' => $health->queueDepths(),
        ]);
    }
}
