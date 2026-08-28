<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['image_base64'])]
final class UserSignatureAsset extends Model
{
    protected $table = 'user_signature_assets';
    protected $primaryKey = 'signature_asset_id';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'uploaded_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }
}
