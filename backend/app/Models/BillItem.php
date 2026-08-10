<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillItem extends Model
{
    protected $table = 'bill_items';

    protected $primaryKey = 'item_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'sale_discount_unit' => 'decimal:2',
            'line_total_before_discount' => 'decimal:2',
            'admin_disocount_unit' => 'decimal:2',
            'admin_weight_unit' => 'decimal:2',
        ];
    }

    public function billingRecord(): BelongsTo
    {
        return $this->belongsTo(BillingRecord::class, 'bill_id', 'bill_id');
    }
}
