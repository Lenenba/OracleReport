<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MappingFieldEnv extends Model
{
    protected $fillable = [
        'object_field_id',
        'environment_id',
        'machine_name',
    ];
}
