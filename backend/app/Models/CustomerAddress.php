<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CustomerAddress extends Model
{
    protected $primaryKey = 'address_id';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_default_billing' => 'boolean',
            'is_default_delivery' => 'boolean',
            'is_active' => 'boolean',
            'bplus_synced_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
