<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MoveRequestApplication extends Model
{
    protected $fillable = [
        'move_request_id',
        'user_id',
        'offered_price',
        'delivery_time',
        'message',
        'negotiable',
        'status',
    ];

    protected $casts = [
        'offered_price' => 'decimal:2',
        'negotiable' => 'boolean',
    ];

    public function moveRequest(): BelongsTo
    {
        return $this->belongsTo(MoveRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(MoveRequestApplicationService::class);
    }
}
