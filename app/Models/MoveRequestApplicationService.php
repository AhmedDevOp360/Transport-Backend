<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoveRequestApplicationService extends Model
{
    protected $fillable = [
        'move_request_application_id',
        'service_name',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(MoveRequestApplication::class, 'move_request_application_id');
    }
}
