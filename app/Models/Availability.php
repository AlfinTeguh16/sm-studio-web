<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Availability extends Model {
    protected $table = 'availability';
    protected $fillable = ['mua_id','available_date','time_slots'];
    protected $casts = [
        'available_date' => 'date', // pastikan ini ada
        'time_slots'     => 'array',
    ];
}