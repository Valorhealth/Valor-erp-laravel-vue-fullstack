<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FibroscanBooking extends Model
{
    protected $table = 'fibroscan_bookings';

    protected $primaryKey = 'booking_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reserved_from' => 'datetime',
            'reserved_until' => 'datetime',
        ];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(FibroscanMachine::class, 'machine_id', 'machine_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by_user_id', 'id');
    }
}
