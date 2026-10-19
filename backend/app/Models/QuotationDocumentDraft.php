<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class QuotationDocumentDraft extends Model
{
    protected $primaryKey = 'draft_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'lock_version' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
