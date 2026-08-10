<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditRequest extends Model
{
    protected $table = 'credit_requests';

    protected $primaryKey = 'request_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'approved_at' => 'datetime',
            'opened_at' => 'datetime',
            'requested_credit_limit' => 'decimal:2',
            'current_credit_limit' => 'decimal:2',
            'expected_monthly_volume' => 'decimal:2',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CreditRequestDocument::class, 'request_id', 'request_id');
    }
}
