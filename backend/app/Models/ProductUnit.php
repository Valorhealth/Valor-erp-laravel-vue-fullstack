<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductUnit extends Model
{
    protected $table = 'product_units';

    protected $primaryKey = 'unit_id';

    protected $guarded = [];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(MasterProduct::class, 'master_product_units', 'unit_id', 'sku_code')
            ->withPivot('is_default')
            ->withTimestamps();
    }
}
