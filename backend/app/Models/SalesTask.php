<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesTask extends Model
{
    protected $table = 'sales_tasks';

    protected $primaryKey = 'task_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'all_day' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
