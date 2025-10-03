<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Portfolio extends Model
{
    protected $table = 'portfolios';

    protected $fillable = ['mua_id','name','photos','makeup_type','collaboration'];

    protected $casts = [
        'photos'     => 'array',     // JSON -> array otomatis
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Default value agar tidak null saat diambil
    protected $attributes = [
        'photos' => '[]',
    ];

    /** Relasi: Portfolio dimiliki oleh MUA (Profile) */
    public function mua(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'mua_id');
    }

    /** Scope cepat untuk filter berdasarkan MUA */
    public function scopeForMua(Builder $query, string $muaId): Builder
    {
        return $query->where('mua_id', $muaId);
    }

    /** Aksesori jumlah foto (otomatis muncul sebagai atribut) */
    public function getPhotosCountAttribute(): int
    {
        return is_array($this->photos) ? count($this->photos) : 0;
    }
}
