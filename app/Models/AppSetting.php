<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored override of one registered setting (Phase C1). Written only by
 * App\Services\Settings\SettingsRepository, which validates the value against
 * the registry and audits the change in the same transaction.
 */
class AppSetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'value', 'updated_by', 'updated_by_ref'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
