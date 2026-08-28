<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['signature_image_base64', 'payload_snapshot'])]
final class DocumentSignature extends Model
{
    protected $table = 'document_signatures';
    protected $primaryKey = 'signature_id';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload_snapshot' => 'array',
            'signed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
