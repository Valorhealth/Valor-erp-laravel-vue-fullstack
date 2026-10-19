<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class QuotationPowerOfAttorneyVersion extends Model
{
    protected $primaryKey = 'authorization_version_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['draft_pdf_base64'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

}
