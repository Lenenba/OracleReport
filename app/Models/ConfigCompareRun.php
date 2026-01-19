<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfigCompareRun extends Model
{
    protected $fillable = [
        'left_label',
        'right_label',
        'left_source',
        'right_source',
        'status',
        'payload',
        'user_id',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
