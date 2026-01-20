<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MappingObjectEnv extends Model
{
    protected $fillable = [
        'object_id',
        'environment_id',
        'table_name',
        'field_count',
    ];
}
