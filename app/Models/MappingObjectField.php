<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MappingObjectField extends Model
{
    protected $fillable = [
        'object_id',
        'name',
        'name_key',
    ];
}
