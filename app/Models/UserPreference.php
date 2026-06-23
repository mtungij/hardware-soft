<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    protected $fillable = [
        'guard',
        'user_id',
        'key',
        'value',
    ];
}
