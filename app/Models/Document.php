<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tracking_code',
        'title',
        'guest_info',
        'purpose_id',
        'details',
        'district',
        'department',
        'status',
        'decline_reason',
        'declined_at',
        'finalized_route',
        'current_step',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'guest_info' => 'array',
        'finalized_route' => 'array',
        'declined_at' => 'datetime',
    ];

    /**
     * Get the purpose associated with the document.
     */
    public function purpose()
    {
        return $this->belongsTo(Purpose::class);
    }

    /**
     * Get the logs for the document.
     */
    public function logs()
    {
        return $this->hasMany(DocumentLog::class);
    }
}
