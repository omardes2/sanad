<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Enums\ChannelAccountStatus;
use App\Enums\ChannelType;
use App\Enums\UserStatus;
use App\Models\ChannelAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns an inbound WhatsApp sender into a first-class subscriber.
 *
 * Every real WhatsApp number becomes its OWN subscriber (a non-admin User) that
 * owns a WhatsApp ChannelAccount — never linked blindly to the operator/admin.
 * Operators (is_admin = true) and subscribers (is_admin = false) share the
 * users table; a subscriber is identified by owning a WhatsApp channel account.
 *
 * The sender identifier is always the normalized E.164 form (with a leading
 * "+") produced by WhatsAppPhone::toE164 — the caller must pass that, so an
 * identifier with or without "+" always resolves to the same account.
 *
 * Race-safety: the account is unique on (channel, external_identifier). Two
 * concurrent first-time messages from the same number can race; the loser hits
 * the unique constraint and re-reads the winner's account. No duplicate
 * subscriber, account, conversation, or message is ever created.
 */
class WhatsAppSubscriberProvisioner
{
    /**
     * Find the WhatsApp channel account for this E.164 number, creating the
     * subscriber and account on first contact.
     */
    public function resolve(string $e164, ?string $profileName = null): ChannelAccount
    {
        $existing = $this->find($e164);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($e164, $profileName): ChannelAccount {
                $user = User::create([
                    'name' => $this->displayName($profileName),
                    'phone' => $e164,
                    'is_admin' => false,
                    'status' => UserStatus::Active,
                ]);

                return ChannelAccount::create([
                    'user_id' => $user->id,
                    'channel' => ChannelType::WhatsApp,
                    'external_identifier' => $e164,
                    'display_name' => $this->nullableName($profileName),
                    'status' => ChannelAccountStatus::Active,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent first message from the same number won the race
            // (account or user phone unique constraint). Reuse its account.
            return $this->find($e164) ?? throw new UniqueConstraintViolationException(
                'pgsql',
                'select channel_accounts',
                [],
                new \RuntimeException('channel account vanished after unique violation'),
            );
        }
    }

    private function find(string $e164): ?ChannelAccount
    {
        return ChannelAccount::query()
            ->where('channel', ChannelType::WhatsApp)
            ->where('external_identifier', $e164)
            ->first();
    }

    private function displayName(?string $profileName): string
    {
        $name = $this->nullableName($profileName);

        return $name ?? 'مشترك واتساب';
    }

    private function nullableName(?string $profileName): ?string
    {
        if ($profileName === null) {
            return null;
        }

        $name = trim($profileName);

        return $name === '' ? null : $name;
    }
}
