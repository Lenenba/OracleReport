<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MappingEnvironment extends Model
{
    protected $fillable = [
        'code',
        'label',
        'source_path',
        'source_mtime',
    ];

    protected $casts = [
        'source_mtime' => 'integer',
    ];
}
