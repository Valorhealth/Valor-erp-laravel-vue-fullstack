<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
            'req_tax_invoice' => 'boolean',
            'tax_info_filled' => 'boolean',
            'total_amount' => 'decimal:2',
            'sale_discount_total' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'credit_outstanding' => 'decimal:2',
            'credit_remain' => 'decimal:2',
            'admin_discount_final' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class, 'bill_id', 'bill_id');
    }
}
