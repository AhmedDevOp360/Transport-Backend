<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'model',
        'color',
        'year',
        'license_plate',
        'rate_per_km',
        'hourly_rate',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
