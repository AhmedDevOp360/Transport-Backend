<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MoveRequest extends Model
{
    protected $fillable = [
        'user_id',
        'driver_id',
        'move_type',
        'vehicle_type',
        'move_title',
        'pickup_address',
        'drop_address',
        'move_date',
        'move_time',
        'property_size',
        'budget_min',
        'budget_max',
        'estimated_delivery_date',
        'status',
        'description',
    ];

    protected $casts = [
        'move_date' => 'date',
        'move_time' => 'datetime:H:i:s',
        'estimated_delivery_date' => 'date',
        'budget_min' => 'decimal:2',
        'budget_max' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MoveRequestItem::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(MoveRequestApplication::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
