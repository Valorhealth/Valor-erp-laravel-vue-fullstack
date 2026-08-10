<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EditLog extends Model
{
    protected $table = 'edit_logs';

    protected $primaryKey = 'log_id';

    public $timestamps = false;

    protected $guarded = [];
}
