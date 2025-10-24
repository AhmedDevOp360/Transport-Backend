<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserDocument extends Model
{
    protected $fillable = [
        'user_id',
        'document_path',
        'document_type',
        'original_filename',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
