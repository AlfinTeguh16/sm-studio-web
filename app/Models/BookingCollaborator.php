<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingCollaborator extends Model
{
    protected $table = 'booking_collaborators';

    protected $fillable = [
        'booking_id',
        'profile_id',
        'role',
        'status',
        'share_amount',
        'share_percent',
        'invited_at',
        'responded_at',
    ];

    protected $casts = [
        'invited_at' => 'datetime',
        'responded_at' => 'datetime',
        'share_amount' => 'decimal:2',
        'share_percent' => 'decimal:2',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function profile()
    {
        return $this->belongsTo(Profile::class, 'profile_id', 'id');
    }
}
