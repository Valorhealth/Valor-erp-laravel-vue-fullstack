<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesCallActivity extends Model
{
    protected $table = 'sales_call_activities';

    protected $primaryKey = 'call_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date:Y-m-d',
            'next_follow_up_date' => 'date:Y-m-d',
            'estimated_value' => 'decimal:2',
            'actual_value' => 'decimal:2',
            'duration_minutes' => 'integer',
            'call_no' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }
}
