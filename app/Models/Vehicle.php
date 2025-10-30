<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vehicle extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'vehicle_name',
        'plate_number',
        'vehicle_type',
        'capacity_tons',
        'model',
        'color',
        'year',
        'license_plate',
        'rate_per_km',
        'hourly_rate',
        'last_used',
        'status',
        'notes',
    ];

    protected $casts = [
        'last_used' => 'date',
        'capacity_tons' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
