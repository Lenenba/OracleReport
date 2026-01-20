<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MappingObjectFieldMap extends Model
{
    protected $fillable = [
        'object_id',
        'from_environment_id',
        'to_environment_id',
        'from_table',
        'to_table',
        'field_name',
        'from_column',
        'to_column',
    ];
}
