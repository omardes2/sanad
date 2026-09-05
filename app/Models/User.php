<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Contracts\Security\HasSensitiveAttributes;
use App\Enums\ReplyMode;
use App\Enums\UserStatus;
use App\Support\Rbac\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasSensitiveAttributes
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
        'timezone',
        'locale',
        'currency',
        'preferred_reply_mode',
        'status',
        'is_admin',
        'onboarding_completed_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'password' => 'hashed',
            'preferred_reply_mode' => ReplyMode::class,
            'status' => UserStatus::class,
            'is_admin' => 'boolean',
        ];
    }

    /**
     * The legacy operator flag (pre-RBAC). Still honoured for the dashboard
     * pages that pre-date Phase C0; new sensitive pages require a permission.
     */
    public function isAdmin(): bool
    {
        return $this->is_admin === true;
    }

    /**
     * Whether this user may enter the operator dashboard at all: the legacy
     * flag, or a role granting `dashboard.access` (see the "admin" middleware).
     */
    public function canAccessDashboard(): bool
    {
        return $this->isAdmin() || $this->can(Permission::DashboardAccess->value);
    }

    /**
     * Attributes that must never appear in audit rows, logs or exports.
     *
     * @return list<string>
     */
    public function sensitiveAttributes(): array
    {
        return ['password', 'remember_token'];
    }

    // ------------------------------------------------------------------
    // Relations — a user owns all of their personal data.
    // ------------------------------------------------------------------

    /** @return HasMany<ChannelAccount, $this> */
    public function channelAccounts(): HasMany
    {
        return $this->hasMany(ChannelAccount::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<Reminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /** @return HasMany<Memory, $this> */
    public function memories(): HasMany
    {
        return $this->hasMany(Memory::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return HasMany<UsageEvent, $this> */
    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /** @return HasOne<Subscription, $this> */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class, 'subscriber_id');
    }

    /** @return HasMany<UsageCounter, $this> */
    public function usageCounters(): HasMany
    {
        return $this->hasMany(UsageCounter::class, 'subscriber_id');
    }
}
