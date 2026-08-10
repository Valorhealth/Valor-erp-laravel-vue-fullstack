<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditProfile extends Model
{
    protected $table = 'customer_credit_profiles';

    protected $primaryKey = 'ar_code';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'credit_outstanding' => 'decimal:2',
            'updated_at' => 'datetime',
        ];
    }
}
