<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesCallImportBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'report_month' => 'date:Y-m-d',
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
            'file_size' => 'integer',
            'imported_rows' => 'integer',
            'source_activity_rows' => 'integer',
            'data_deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }
}
