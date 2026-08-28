<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['username', 'password_hash', 'password_changed_at', 'first_name', 'last_name', 'email', 'department', 'role', 'quotation_signing_authorized'])]
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
        ];
    }

    public function signatureAssets(): HasMany
    {
        return $this->hasMany(UserSignatureAsset::class, 'user_id', 'id');
    }
}
