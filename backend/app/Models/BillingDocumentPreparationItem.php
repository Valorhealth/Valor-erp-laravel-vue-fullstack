<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BillingDocumentPreparationItem extends Model
{
    protected $table = 'billing_document_preparation_items';

    protected $primaryKey = 'preparation_item_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'updated_at' => 'datetime',
            'prepared_at' => 'datetime',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(BillingRecord::class, 'bill_id', 'bill_id');
    }

    public function preparedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by', 'id');
    }

    public function attachmentDocument(): BelongsTo
    {
        return $this->belongsTo(BillingDocument::class, 'attachment_document_id', 'doc_id');
    }
}
