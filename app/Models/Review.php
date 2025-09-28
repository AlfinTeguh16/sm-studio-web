<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model {
    protected $fillable = ['booking_id','customer_id','mua_id','rating','comment'];
 }