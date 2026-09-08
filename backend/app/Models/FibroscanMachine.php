<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FibroscanMachine extends Model
{
    protected $table = 'fibroscan_machines';

    protected $primaryKey = 'machine_id';

    protected $guarded = [];

    public function bookings(): HasMany
    {
        return $this->hasMany(FibroscanBooking::class, 'machine_id', 'machine_id');
    }
}
