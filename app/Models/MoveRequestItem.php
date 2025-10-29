<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoveRequestItem extends Model
{
    protected $fillable = [
        'move_request_id',
        'item_name',
        'quantity',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function moveRequest(): BelongsTo
    {
        return $this->belongsTo(MoveRequest::class);
    }
}
