<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Profile extends Model {
    public $incrementing = false; // uuid
    protected $keyType = 'string';
    protected $fillable = ['id','role','name','phone','bio','photo_url','services','location_lat','location_lng','address','is_online'];
    protected $casts = [
    'services' => 'array',
    'is_online' => 'boolean',
    ];

    public function offerings(): HasMany { return $this->hasMany(Offering::class, 'mua_id'); }
    public function portfolios(): HasMany { return $this->hasMany(Portfolio::class, 'mua_id'); }

    public function collaborations()
    {
        return $this->belongsToMany(
            Booking::class,
            'booking_collaborators',
            'profile_id',
            'booking_id'
        )->withPivot(['role','status','share_amount','share_percent','invited_at','responded_at'])
        ->withTimestamps();
    }

}