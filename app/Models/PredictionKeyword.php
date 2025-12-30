<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PredictionKeyword extends Model
{
    protected $fillable = [
        'keyword',
        'department_id',
        'weight',
    ];
}
