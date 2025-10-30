<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Driver extends Model
{
    protected $fillable = [
        'user_id',
        'team_name',
        'status',
        'job_assignment',
        'truck_number',
        'rating',
        'license_expiry',
        'vehicle_maintenance_due',
        'completed_jobs',
        'assigned_vehicle_id',
    ];

    protected $casts = [
        'license_expiry' => 'date',
        'vehicle_maintenance_due' => 'date',
        'rating' => 'decimal:1',
        'completed_jobs' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'assigned_vehicle_id');
    }
}
