<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesCallMonthlySubmission extends Model
{
    protected $table = 'sales_call_monthly_submissions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'report_month' => 'date:Y-m-d',
            'used_excel' => 'boolean',
            'used_website' => 'boolean',
            'first_submitted_at' => 'datetime',
            'last_edited_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }
}
