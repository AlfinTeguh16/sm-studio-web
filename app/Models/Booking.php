<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class Booking extends Model
{
    use HasFactory;

    protected $table = 'bookings';

    protected $fillable = [
        'customer_id','mua_id','offering_id',
        'booking_date','booking_time','person' ,'service_type','location_address','notes',
        'invoice_number','invoice_date','due_date',
        'amount','selected_add_ons','subtotal','tax_amount','discount_amount','grand_total',
        'tax','total',
        'status','job_status',
        'payment_method','payment_status','paid_at',
        'use_collaboration',
    ];

    protected $casts = [
        'booking_date' => 'date:Y-m-d',
        'booking_time' => 'datetime:H:i',
        'invoice_date'      => 'date',
        'due_date'          => 'date',
        'paid_at'           => 'datetime',
        'selected_add_ons'  => 'array',
        'amount'            => 'decimal:2',
        'subtotal'          => 'decimal:2',
        'tax_amount'        => 'decimal:2',
        'discount_amount'   => 'decimal:2',
        'grand_total'       => 'decimal:2',
        'use_collaboration' => 'boolean',
        'is_collaborative' => 'boolean',
    ];

    /* ========= RELATIONS (opsional, aman) ========= */
    public function customer()
    {
        return $this->belongsTo(Profile::class, 'customer_id', 'id');
    }

    public function mua()
    {
        return $this->belongsTo(Profile::class, 'mua_id', 'id')
                    ->select(['id','name','photo_url']);
    }

    public function offering()
    {
        return $this->belongsTo(Offering::class, 'offering_id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'booking_id');
    }

    /* ========= BOOT & HOOKS (tanpa magic props) ========= */
    protected static function booted()
    {
        static::creating(function (Booking $booking) {
            // invoice_number
            if (empty($booking->getAttribute('invoice_number'))) {
                $booking->setAttribute('invoice_number', 'INV-'.now()->format('Ymd').'-'.Str::upper(Str::random(4)));
            }
            // invoice_date (pakai Carbon)
            if (empty($booking->getAttribute('invoice_date'))) {
                $booking->setAttribute('invoice_date', Carbon::now());
            }
            // default states
            if (empty($booking->getAttribute('payment_status'))) {
                $booking->setAttribute('payment_status', 'unpaid');
            }
            if (empty($booking->getAttribute('job_status'))) {
                $booking->setAttribute('job_status', 'pending');
            }

            // hitung total jika ada data awal
            $booking->computeTotals($booking->getAttribute('selected_add_ons'));
        });

        static::updating(function (Booking $booking) {
            if ($booking->isDirty(['amount','selected_add_ons','discount_amount','tax'])) {
                $addOns = $booking->getAttribute('selected_add_ons');
                if (is_string($addOns)) {
                    $decoded = json_decode($addOns, true);
                    $addOns = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
                }
                $booking->computeTotals($addOns);
            }
        });
    }

    /* ========= HELPERS (tanpa magic props) ========= */

    /**
     * Hitung subtotal, pajak (persen dari 'tax' legacy), discount, grand_total.
     * Simpan nilai decimal sebagai string terformat (sprintf) agar konsisten dengan cast decimal:2.
     */
    public function computeTotals(?array $addOns = null): void
    {
        $amount     = (float) ($this->getAttribute('amount') ?? 0);
        $discount   = (float) ($this->getAttribute('discount_amount') ?? 0);
        $taxRaw     = $this->getAttribute('tax');
        $taxPercent = (is_numeric($taxRaw) ? (float) $taxRaw : 0.0);

        if (is_array($addOns)) {
            foreach ($addOns as $a) {
                $amount += (float) ($a['price'] ?? 0);
            }
        }

        $subtotal   = round($amount, 2);
        $taxAmount  = round($subtotal * ($taxPercent / 100), 2);
        $grandTotal = round($subtotal + $taxAmount - $discount, 2);

        $this->setAttribute('subtotal',        sprintf('%.2f', $subtotal));
        $this->setAttribute('tax_amount',      sprintf('%.2f', $taxAmount));
        $this->setAttribute('discount_amount', sprintf('%.2f', $discount));
        $this->setAttribute('grand_total',     sprintf('%.2f', $grandTotal));
    }

    /** Mulai pekerjaan oleh MUA */
    public function markInProgress(): self
    {
        $this->setAttribute('job_status', 'in_progress');
        $this->save();
        return $this;
    }

    /** MUA menekan tombol "Selesai" */
    public function markComplete(): self
    {
        $this->setAttribute('job_status', 'completed');
        $this->save();
        return $this;
    }

    /** Batalkan booking/invoice (refund/void sederhana) */
    public function cancel(): self
    {
        $this->setAttribute('job_status', 'cancelled');

        $currentPay = (string) $this->getAttribute('payment_status');
        $this->setAttribute('payment_status', $currentPay === 'paid' ? 'refunded' : 'void');

        $this->save();
        return $this;
    }

    /* ========= SCOPES (aman) ========= */
    public function scopeForMua($q, string $muaId)
    {
        return $q->where('mua_id', $muaId);
    }

    public function scopeForCustomer($q, string $customerId)
    {
        return $q->where('customer_id', $customerId);
    }

    public function scopeActive($q)
    {
        return $q->whereIn('job_status', ['pending','confirmed','in_progress']);
    }

    public function scopeUnpaid($q)
    {
        return $q->where('payment_status', 'unpaid');
    }


    public function collaborators()
    {
        return $this->belongsToMany(
            Profile::class,
            'booking_collaborators',
            'booking_id',
            'profile_id'
        )->withPivot(['role','status','share_amount','share_percent','invited_at','responded_at'])
        ->withTimestamps();
    }

    public function acceptedCollaborators()
    {
        return $this->belongsToMany(
            Profile::class,
            'booking_collaborators',
            'booking_id',
            'profile_id'
        )->withPivot(['role','status'])
        ->wherePivot('status', 'accepted')
        ->withTimestamps();
    }
}
