<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['username', 'password_hash', 'password_changed_at', 'first_name', 'last_name', 'email', 'phone', 'job_title', 'representative_address', 'national_id', 'national_id_issued_at', 'national_id_expires_at', 'department', 'role', 'salesman_id', 'quotation_signing_authorized', 'permission_overrides', 'preferences'])]
#[Hidden(['password_hash'])]
class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    public const UPDATED_AT = null;

    protected $table = 'users';

    protected $primaryKey = 'id';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'quotation_signing_authorized' => 'boolean',
            'preferences' => 'array',
            'representative_address' => 'encrypted',
            'national_id' => 'encrypted',
            'national_id_issued_at' => 'date:Y-m-d',
            'national_id_expires_at' => 'date:Y-m-d',
        ];
    }

    protected function permissionOverrides(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): array => (array) json_decode((string) ($value ?: '{}'), true),
            set: fn (mixed $value): string => json_encode((object) ((array) $value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    public function signatureAssets(): HasMany
    {
        return $this->hasMany(UserSignatureAsset::class, 'user_id', 'id');
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class, 'salesman_id', 'id');
    }

    public function workflowNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class, 'user_id', 'id');
    }
}
