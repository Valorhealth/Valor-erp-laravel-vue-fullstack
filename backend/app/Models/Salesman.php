<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Salesman extends Model
{
    protected $table = 'salesmen';

    public $timestamps = false;

    protected $guarded = [];

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'salesman_id', 'id');
    }
}
