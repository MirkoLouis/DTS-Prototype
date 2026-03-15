<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IntegrityCheck extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'user_id',
        'status',
        'progress',
        'results',
        'error_message',
    ];

    protected $casts = [
        'results' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
