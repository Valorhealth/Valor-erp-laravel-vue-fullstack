<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Quotation extends Model
{
    protected $table = 'quotations';

    protected $primaryKey = 'qt_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qt_date' => 'date:Y-m-d',
            'created_at' => 'datetime',
            'quotation_printed_at' => 'datetime',
            'credit_approved_at' => 'datetime',
            'credit_opened_at' => 'datetime',
            'customer_confirmed_at' => 'date:Y-m-d',
            'quotation_valid_until' => 'date:Y-m-d',
            'delivery_due_date' => 'date:Y-m-d',
            'canceled_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'sale_discount_total' => 'decimal:2',
            'credit_limit' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class, 'qt_id', 'qt_id');
    }

    public function billingRecord(): HasOne
    {
        return $this->hasOne(BillingRecord::class, 'ref_qt_id', 'qt_id');
    }
}
