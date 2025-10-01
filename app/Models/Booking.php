<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model {
    protected $fillable = ['customer_id',
    'mua_id',
    'offering_id',
    'booking_date',
    'booking_time',
    'service_type',
    'status',
    'payment_method',
    'amount',
    'payment_status',
    'location_address',
    'notes',
    'tax',
    'total'];
    protected $casts = [
        'booking_date'    => 'date', // pastikan ini ada
        'payment_metadata'=> 'array',
        'paid_at'         => 'datetime',
    ];
}