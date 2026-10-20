<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingRecord extends Model
{
    protected $table = 'billing_records';

    protected $primaryKey = 'bill_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date:Y-m-d',
            'created_at' => 'datetime',
            'document_printed_at' => 'datetime',
            'excel_exported_at' => 'datetime',
            'submitted_at' => 'datetime',
            'issued_at' => 'datetime',
            'customer_confirmed_at' => 'date:Y-m-d',
            'quotation_valid_until' => 'date:Y-m-d',
            'delivery_due_date' => 'date:Y-m-d',
            'canceled_at' => 'datetime',
            'customer_acceptance_expires_at' => 'datetime',
            'customer_acceptance_used_at' => 'datetime',
            'req_tax_invoice' => 'boolean',
            'tax_info_filled' => 'boolean',
            'total_amount' => 'decimal:2',
            'sale_discount_total' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'credit_outstanding' => 'decimal:2',
            'credit_remain' => 'decimal:2',
            'admin_discount_final' => 'decimal:2',
            'submission_document_requirements' => 'array',
            'standard_show_billing_address' => 'boolean',
            'standard_show_delivery_address' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class, 'bill_id', 'bill_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id', 'id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'ref_qt_id', 'qt_id');
    }

    public function quotationDocumentVersion(): BelongsTo
    {
        return $this->belongsTo(QuotationDocumentVersion::class, 'quotation_document_version_id', 'document_version_id');
    }

    public function powerOfAttorneyVersion(): HasMany
    {
        return $this->hasMany(QuotationPowerOfAttorneyVersion::class, 'bill_id', 'bill_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BillingDocument::class, 'bill_id', 'bill_id')
            ->where('is_current', true)
            ->select(['doc_id', 'bill_id', 'document_type', 'file_name', 'mime_type', 'version_no', 'sha256', 'uploaded_by', 'uploaded_at']);
    }

    public function documentPreparationItems(): HasMany
    {
        return $this->hasMany(BillingDocumentPreparationItem::class, 'bill_id', 'bill_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(DocumentSignature::class, 'bill_id', 'bill_id');
    }

    public function customerAcceptanceSignatures(): HasMany
    {
        return $this->hasMany(CustomerAcceptanceSignature::class, 'bill_id', 'bill_id');
    }
}
