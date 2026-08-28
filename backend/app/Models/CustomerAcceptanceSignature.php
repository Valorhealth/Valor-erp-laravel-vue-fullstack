<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['signature_image_base64', 'payload_snapshot'])]
final class CustomerAcceptanceSignature extends Model
{
    protected $table = 'customer_acceptance_signatures';

    protected $primaryKey = 'acceptance_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload_snapshot' => 'array',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
