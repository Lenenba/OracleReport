<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfigCompareEntry extends Model
{
    protected $fillable = [
        'name',
        'direction',
        'source_label',
        'target_label',
        'input_sql',
        'output_sql',
        'replacements',
        'issues',
        'user_id',
    ];

    protected $casts = [
        'replacements' => 'array',
        'issues' => 'array',
    ];
}
