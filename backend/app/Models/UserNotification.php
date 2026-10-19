<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UserNotification extends Model
{
    protected $primaryKey = 'notification_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
