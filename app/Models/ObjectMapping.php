<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ObjectMapping extends Model
{
    protected $fillable = [
        'environment',
        'object_name',
        'table_name',
        'fields',
        'field_count',
        'source_mtime',
        'source_path',
    ];

    protected $casts = [
        'fields' => 'array',
        'source_mtime' => 'integer',
    ];
}
