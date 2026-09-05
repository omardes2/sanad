<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Security\HasSensitiveAttributes;
use App\Enums\CredentialStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider credential at rest (Phase C3). Holds ONLY the sealed
 * ciphertext plus its display forms; the plaintext exists in memory as a
 * SecretString for the duration of one request. Written only by
 * CredentialManager (lifecycle) and the master-key rotation command
 * (ciphertext + key_id of the SAME secret). Never deleted.
 */
class ProviderCredential extends Model implements HasSensitiveAttributes
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_id', 'label', 'kind', 'ciphertext', 'key_id', 'fingerprint', 'last4', 'status',
        'rotated_from_id', 'created_by', 'created_by_ref', 'revoked_by_ref', 'activated_at', 'revoked_at', 'last_verified_at',
    ];

    /**
     * Never in toArray()/JSON — a Livewire snapshot or a log context of this
     * model can therefore not carry the ciphertext.
     *
     * @var list<string>
     */
    protected $hidden = ['ciphertext'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CredentialStatus::class,
            'activated_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_verified_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function sensitiveAttributes(): array
    {
        return ['ciphertext'];
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function rotatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rotated_from_id');
    }

    public function isActive(): bool
    {
        return $this->status === CredentialStatus::Active;
    }

    public function isPending(): bool
    {
        return $this->status === CredentialStatus::Pending;
    }

    /**
     * Display handle: fingerprint + last4, never the value.
     */
    public function masked(): string
    {
        return $this->fingerprint.' … '.$this->last4;
    }
}
