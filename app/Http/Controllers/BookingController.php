<?php

namespace App\Http\Controllers;


use App\Models\{Booking, Availability, Profile, Notification, Offering};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class BookingController extends Controller
{
    /**
     * GET /bookings
     * Query (opsional):
     * - role=customer|mua|admin (default: dari profile user)
     * - userId=uuid (default: auth user)
     * - status=comma,separated (ex: pending,confirmed)
     * - date_from=YYYY-MM-DD, date_to=YYYY-MM-DD
     */
    public function index(Request $req)
    {
        $authId = $req->user()->id;
        $profile = Profile::findOrFail($authId);
        $role = $req->query('role', $profile->role);
        $userId = $req->query('userId', $authId);

        $statuses = $req->filled('status')
            ? array_filter(explode(',', $req->query('status')))
            : null;

        $q = Booking::query();

        // Scope by role (admin boleh lihat semua jika tidak filter)
        if ($role === 'mua') {
            $q->where('mua_id', $userId);
        } elseif ($role === 'customer') {
            $q->where('customer_id', $userId);
        } else {
            // admin → boleh filter dengan muaId/customerId
            if ($req->filled('muaId')) $q->where('mua_id', $req->query('muaId'));
            if ($req->filled('customerId')) $q->where('customer_id', $req->query('customerId'));
        }

        if ($statuses) $q->whereIn('status', $statuses);
        if ($req->filled('date_from')) $q->whereDate('booking_date', '>=', $req->query('date_from'));
        if ($req->filled('date_to'))   $q->whereDate('booking_date', '<=', $req->query('date_to'));

        // return terbaru dulu
        $data = $q->orderByDesc('created_at')->get();

        return response()->json($data);
    }

    /**
     * GET /bookings/{booking}
     * Hanya customer/mua terkait atau admin.
     */
    public function show(Request $req, Booking $booking)
    {
        $authId = $req->user()->id;
        $role = Profile::findOrFail($authId)->role;

        if (!in_array($role, ['admin']) && !in_array($authId, [$booking->customer_id, $booking->mua_id])) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        return response()->json($booking);
    }

    /**
     * POST /bookings
     * Body:
     *  - customer_id (uuid), mua_id (uuid), offering_id? (int), booking_date (Y-m-d), booking_time (HH:MM),
     *  - service_type: home_service|studio
     *  - amount? (number) → jika tidak dikirim & ada offering, amount = offering.price (+collab price jika dipilih)
     *  - use_collaboration? (bool), selected_add_ons? (array of string) → disimpan ke payment_metadata
     */
    public function store(Request $req)
    {
        $data = $req->validate([
            'customer_id'      => ['required','uuid'],
            'mua_id'           => ['required','uuid'],
            'offering_id'      => ['nullable','integer','exists:offerings,id'],
            'booking_date'     => ['required','date_format:Y-m-d'],
            'booking_time'     => ['required','date_format:H:i'],
            'service_type'     => ['required', Rule::in(['home_service','studio'])],
            'amount'           => ['nullable','numeric'],
            'use_collaboration'=> ['nullable','boolean'],
            'selected_add_ons' => ['nullable','array'],
            'selected_add_ons.*' => ['string','max:100'],
        ]);

        // Cek slot tersedia
        $avail = Availability::where('mua_id', $data['mua_id'])
            ->whereDate('available_date', $data['booking_date'])
            ->first();

        if (!$avail || !in_array($data['booking_time'], $avail->time_slots ?? [])) {
            return response()->json(['error' => 'Slot tidak tersedia'], 422);
        }

        // Hitung amount jika tidak dikirim
        $amount = $data['amount'] ?? null;
        $paymentMeta = [];

        if (!empty($data['selected_add_ons'])) {
            $paymentMeta['selected_add_ons'] = $data['selected_add_ons'];
        }

        if (isset($data['use_collaboration'])) {
            $paymentMeta['use_collaboration'] = (bool)$data['use_collaboration'];
        }

        if ($amount === null && !empty($data['offering_id'])) {
            $off = Offering::find($data['offering_id']);
            if ($off) {
                $amount = (float)$off->price;
                if (!empty($data['use_collaboration']) && $off->collaboration && $off->collaboration_price !== null) {
                    $amount += (float)$off->collaboration_price;
                }
            }
        }

        // Set status selalu pending saat dibuat
        $payload = [
            'customer_id'      => $data['customer_id'],
            'mua_id'           => $data['mua_id'],
            'offering_id'      => $data['offering_id'] ?? null,
            'booking_date'     => $data['booking_date'],
            'booking_time'     => $data['booking_time'],
            'service_type'     => $data['service_type'],
            'status'           => 'pending',
            'amount'           => $amount,
            'payment_status'   => 'unpaid',
        ];
        if (!empty($paymentMeta)) $payload['payment_metadata'] = $paymentMeta;

        // Simpan booking + notifikasi, handle race condition oleh unique index
        try {
            $booking = null;
            DB::transaction(function () use (&$booking, $payload) {
                $booking = Booking::create($payload);
                Notification::create([
                    'user_id' => $payload['mua_id'],
                    'title'   => 'Booking baru',
                    'message' => 'Ada booking baru pada '.$payload['booking_date'].' '.$payload['booking_time'],
                    'type'    => 'booking',
                ]);
            });
        } catch (\Throwable $e) {
            // kemungkinan bentrok unique index (double booking)
            return response()->json(['error' => 'Slot sudah terisi'], 409);
        }

        return response()->json($booking, 201);
    }

    /**
     * PATCH /bookings/{booking}/status
     * Body: status = confirmed|rejected|cancelled|completed
     * Optional: reason (string)
     * Rules:
     * - confirmed/rejected: hanya MUA pemilik booking
     * - cancelled: customer atau MUA boleh (selama belum completed)
     * - completed: hanya MUA
     * - confirmed: MUA harus online
     */
    public function updateStatus(Request $req, Booking $booking)
    {
        $data = $req->validate([
            'status' => ['required', Rule::in(['confirmed','rejected','cancelled','completed'])],
            'reason' => ['nullable','string','max:500'],
        ]);

        $authId = $req->user()->id;
        $actorProfile = Profile::findOrFail($authId);

        // Authorization & business rules
        switch ($data['status']) {
            case 'confirmed':
                if ($authId !== $booking->mua_id) return response()->json(['error'=>'Forbidden'], 403);
                $mua = Profile::findOrFail($booking->mua_id);
                if (!$mua->is_online) return response()->json(['error'=>'MUA offline, tidak bisa konfirmasi'], 409);
                break;

            case 'rejected':
                if ($authId !== $booking->mua_id) return response()->json(['error'=>'Forbidden'], 403);
                break;

            case 'cancelled':
                if (!in_array($authId, [$booking->customer_id, $booking->mua_id]) && $actorProfile->role !== 'admin') {
                    return response()->json(['error'=>'Forbidden'], 403);
                }
                if ($booking->status === 'completed') {
                    return response()->json(['error'=>'Sudah selesai, tidak bisa dibatalkan'], 409);
                }
                break;

            case 'completed':
                if ($authId !== $booking->mua_id && $actorProfile->role !== 'admin') {
                    return response()->json(['error'=>'Forbidden'], 403);
                }
                break;
        }

        // Simpan alasan ke payment_metadata.reason (biar tidak ubah skema)
        $meta = $booking->payment_metadata ?? [];
        if (!empty($data['reason'])) $meta['reason'] = $data['reason'];

        $booking->status = $data['status'];
        $booking->payment_metadata = $meta;
        $booking->save();

        // Notifikasi ke pihak lain
        $target = $authId === $booking->mua_id ? $booking->customer_id : $booking->mua_id;
        Notification::create([
            'user_id' => $target,
            'title'   => 'Status booking diperbarui',
            'message' => "Status: {$booking->status} ({$booking->booking_date} {$booking->booking_time})",
            'type'    => 'booking',
        ]);

        return response()->json($booking);
    }

    /**
     * PATCH /bookings/{booking}/reschedule
     * Body: booking_date (Y-m-d), booking_time (HH:MM), reason? (string)
     * - Hanya customer atau MUA terkait (atau admin).
     * - Setelah reschedule → status kembali 'pending'.
     * - Cek ketersediaan & konflik unik index.
     */
    public function reschedule(Request $req, Booking $booking)
    {
        $data = $req->validate([
            'booking_date' => ['required','date_format:Y-m-d'],
            'booking_time' => ['required','date_format:H:i'],
            'reason'       => ['nullable','string','max:500'],
        ]);

        $authId = $req->user()->id;
        $role = Profile::findOrFail($authId)->role;
        if (!in_array($authId, [$booking->customer_id, $booking->mua_id]) && $role !== 'admin') {
            return response()->json(['error'=>'Forbidden'], 403);
        }
        if ($booking->status === 'completed') {
            return response()->json(['error'=>'Booking sudah selesai'], 409);
        }

        // Cek slot tersedia
        $avail = Availability::where('mua_id', $booking->mua_id)
            ->whereDate('available_date', $data['booking_date'])
            ->first();

        if (!$avail || !in_array($data['booking_time'], $avail->time_slots ?? [])) {
            return response()->json(['error' => 'Slot tidak tersedia'], 422);
        }

        try {
            DB::transaction(function () use ($booking, $data) {
                $meta = $booking->payment_metadata ?? [];
                if (!empty($data['reason'])) $meta['reschedule_reason'] = $data['reason'];
                $booking->update([
                    'booking_date'     => $data['booking_date'],
                    'booking_time'     => $data['booking_time'],
                    'status'           => 'pending',
                    'payment_metadata' => $meta,
                ]);
                Notification::create([
                    'user_id' => $booking->mua_id,
                    'title'   => 'Permintaan jadwal ulang',
                    'message' => 'Booking diubah ke '.$data['booking_date'].' '.$data['booking_time'],
                    'type'    => 'booking',
                ]);
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Slot sudah terisi'], 409);
        }

        return response()->json($booking->fresh());
        }

    /**
     * POST /bookings/quote
     * Body: offering_id (int), use_collaboration? (bool)
     * Return: { amount }
     */
    public function quote(Request $req)
    {
        $data = $req->validate([
            'offering_id'      => ['required','integer','exists:offerings,id'],
            'use_collaboration'=> ['nullable','boolean'],
        ]);
        $off = Offering::findOrFail($data['offering_id']);
        $amount = (float)$off->price;
        if (!empty($data['use_collaboration']) && $off->collaboration && $off->collaboration_price !== null) {
            $amount += (float)$off->collaboration_price;
        }
        return response()->json(['amount' => $amount]);
    }

    /**
     * GET /bookings/calendar
     * Untuk MUA: kembalikan event list (tanggal, time, status).
     * Query: date_from?, date_to?
     */
    public function calendar(Request $req)
    {
        $authId = $req->user()->id;
        $profile = Profile::findOrFail($authId);

        $q = Booking::where('mua_id', $authId);
        if ($req->filled('date_from')) $q->whereDate('booking_date', '>=', $req->query('date_from'));
        if ($req->filled('date_to'))   $q->whereDate('booking_date', '<=', $req->query('date_to'));

        $rows = $q->orderBy('booking_date')->orderBy('booking_time')->get();

        $events = $rows->map(function (Booking $b) {
            return [
                'id'     => $b->id,
                'title'  => strtoupper($b->status).' • '.$b->service_type,
                'start'  => "{$b->booking_date} {$b->booking_time}:00",
                'status' => $b->status,
                'customer_id' => $b->customer_id,
            ];
        });

        return response()->json($events);
    }

    /**
     * GET /bookings/stats
     * Ringkas: hitung jumlah booking per status (scope user).
     */
    public function stats(Request $req)
    {
        $authId = $req->user()->id;
        $profile = Profile::findOrFail($authId);

        $q = Booking::query();
        if ($profile->role === 'mua') {
            $q->where('mua_id', $authId);
        } elseif ($profile->role === 'customer') {
            $q->where('customer_id', $authId);
        }

        if ($req->filled('date_from')) $q->whereDate('booking_date', '>=', $req->query('date_from'));
        if ($req->filled('date_to'))   $q->whereDate('booking_date', '<=', $req->query('date_to'));

        $data = $q->selectRaw('status, COUNT(id) as count')
            ->groupBy('status')
            ->pluck('count','status');

        return response()->json($data);
    }
}
