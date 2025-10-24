<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetToken extends Model
{
    protected $table = 'password_reset_tokens';

    public $timestamps = false; // We only use created_at

    protected $fillable = [
        'identifier',
        'otp',
        'reset_token',
        'otp_expires_at',
        'reset_token_expires_at',
        'created_at',
    ];

    protected $casts = [
        'otp_expires_at' => 'datetime',
        'reset_token_expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
