<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class QuotationDocumentVersion extends Model
{
    protected $primaryKey = 'document_version_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['pdf_base64'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'revision_no' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function powerOfAttorney(): HasOne
    {
        return $this->hasOne(QuotationPowerOfAttorneyVersion::class, 'document_version_id', 'document_version_id');
    }
}
