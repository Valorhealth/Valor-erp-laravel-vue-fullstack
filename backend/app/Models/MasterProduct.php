<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MasterProduct extends Model
{
    protected $table = 'master_products';

    protected $primaryKey = 'sku_code';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(ProductUnit::class, 'master_product_units', 'sku_code', 'unit_id')
            ->withPivot('is_default')
            ->withTimestamps()
            ->orderByPivot('is_default', 'desc')
            ->orderBy('unit_name');
    }
}
