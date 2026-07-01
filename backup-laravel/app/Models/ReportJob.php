<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReportJob extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'user_id',
        'status',
        'progress',
        'total_documents',
        'file_path',
        'error_message',
    ];
}
