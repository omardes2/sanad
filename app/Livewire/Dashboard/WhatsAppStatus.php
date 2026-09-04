<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Support\WhatsApp\WhatsAppConfig;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Read-only operational view of the WhatsApp integration.
 *
 * PRIVACY: this page exposes ONLY booleans and infra health — never a token,
 * app secret, verify token, or any credential value. Config presence is
 * reported through WhatsAppConfig's boolean capability checks; the raw
 * values are never read or rendered here.
 */
#[Title('حالة واتساب | سَنَد')]
#[Layout('components.layouts.dashboard')]
class WhatsAppStatus extends Component
{
    public function render(WhatsAppConfig $config)
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
            'horizon' => $this->horizonStatus(),
            'redisUp' => $this->redisUp(),
            'queues' => $this->queueSizes(),
        ]);
    }

    /**
     * 'running' | 'inactive' | 'unavailable' — never throws.
     */
    private function horizonStatus(): string
    {
        try {
            $masters = app(MasterSupervisorRepository::class)->all();

            return count($masters) > 0 ? 'running' : 'inactive';
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    private function redisUp(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Pending job counts per known queue. Null means the size could not be read.
     *
     * @return array<string, int|null>
     */
    private function queueSizes(): array
    {
        $queues = ['webhooks', 'messages', 'default'];
        $sizes = [];

        foreach ($queues as $queue) {
            try {
                $sizes[$queue] = Queue::size($queue);
            } catch (Throwable) {
                $sizes[$queue] = null;
            }
        }

        return $sizes;
    }
}
