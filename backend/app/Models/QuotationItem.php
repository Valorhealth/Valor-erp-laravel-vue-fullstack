<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotationItem extends Model
{
    protected $table = 'quotation_items';

    protected $primaryKey = 'qt_item_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'sale_discount_unit' => 'decimal:2',
            'line_total_before_discount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }
}
