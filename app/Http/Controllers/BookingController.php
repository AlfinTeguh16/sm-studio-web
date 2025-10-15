<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Offering;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BookingController extends Controller
{
    /** Context standar untuk log */
    private function ctx(Request $req, array $extra = []): array
    {
        $auth = $req->user();
        $hdr  = array_change_key_case($req->headers->all(), CASE_LOWER);

        // masking header sensitif
        foreach (['authorization','cookie','x-xsrf-token'] as $h) {
            if (isset($hdr[$h])) $hdr[$h] = ['***'];
        }

        // ringkas payload
        $raw = $req->all();
        $rawJson = json_encode($raw);
        if (is_string($rawJson) && strlen($rawJson) > 1200) {
            $rawJson = substr($rawJson, 0, 1200).'...<truncated>';
        }

        return array_merge([
            'rid'     => (string) Str::uuid(),
            'route'   => $req->path(),
            'method'  => $req->method(),
            'ip'      => $req->ip(),
            'user_id' => optional($auth)->id,
            'headers' => $hdr,
            'payload' => $rawJson,
        ], $extra);
    }

    public function index(Request $req)
    {
        $ctx = $this->ctx($req);
        Log::channel('bookings')->info('BOOKING_INDEX_START', $ctx);

        try {
            $q = Booking::query();

            if ($req->filled('mua_id'))         $q->where('mua_id', $req->string('mua_id'));
            if ($req->filled('customer_id'))    $q->where('customer_id', $req->string('customer_id'));
            if ($req->filled('job_status'))     $q->where('job_status', $req->string('job_status'));
            if ($req->filled('payment_status')) $q->where('payment_status', $req->string('payment_status'));

            $per = $req->integer('per_page', 20);
            $data = $q->latest('id')->paginate($per);

            Log::channel('bookings')->info('BOOKING_INDEX_SUCCESS', $ctx + [
                'count'   => $data->count(),
                'perPage' => $per,
                'page'    => $data->currentPage(),
            ]);

            return response()->json($data);
        } catch (\Throwable $e) {
            Log::channel('bookings')->error('BOOKING_INDEX_ERROR', $ctx + [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to list bookings'], 500);
        }
    }

    public function store(Request $req)
    {
        $ctx = $this->ctx($req);
        Log::channel('bookings')->info('BOOKING_STORE_VALIDATE_START', $ctx);

        try {
            $data = $req->validate([
                'customer_id'      => ['required','uuid'],
                'mua_id'           => ['required','uuid'],
                'offering_id'      => ['nullable','integer','exists:offerings,id'],
                'booking_date'     => ['required','date_format:Y-m-d'],
                'booking_time'     => ['required','date_format:H:i'],
                'person'           => ['required','integer','min:1'],
                'service_type'     => ['required', Rule::in(['home_service','studio'])],
                'location_address' => ['nullable','string','max:500'],
                'notes'            => ['nullable','string','max:1000'],

                'invoice_date'     => ['nullable','date'],
                'due_date'         => ['nullable','date','after_or_equal:invoice_date'],

                'amount'           => ['nullable','numeric'],
                'selected_add_ons' => ['nullable','array'],
                'selected_add_ons.*.name'  => ['required_with:selected_add_ons','string','max:100'],
                'selected_add_ons.*.price' => ['required_with:selected_add_ons','numeric'],
                'discount_amount'  => ['nullable','numeric'],
                'tax'              => ['nullable','numeric'],

                'payment_method'   => ['nullable','string','max:50'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            Log::channel('bookings')->warning('BOOKING_STORE_VALIDATE_FAIL', $ctx + [
                'errors' => $ve->errors(),
            ]);
            throw $ve; // biar tetap 422 ke client
        }

        Log::channel('bookings')->info('BOOKING_STORE_DB_START', $ctx);

        try {
            $result = DB::transaction(function () use ($data, $ctx) {
                $booking = new Booking();

                foreach ($data as $k => $v) {
                    if (in_array($k, ['booking_date','invoice_date','due_date']) && !empty($v)) {
                        $booking->setAttribute($k, Carbon::parse($v));
                    } else {
                        $booking->setAttribute($k, $v);
                    }
                }

                if (empty($booking->getAttribute('payment_status'))) $booking->setAttribute('payment_status', 'unpaid');
                if (empty($booking->getAttribute('job_status')))     $booking->setAttribute('job_status', 'confirmed');
                if (empty($booking->getAttribute('invoice_date')))   $booking->setAttribute('invoice_date', Carbon::now());

                $booking->computeTotals($booking->getAttribute('selected_add_ons'));
                $booking->save();

                Log::channel('bookings')->info('BOOKING_STORE_DB_SAVED', $ctx + [
                    'booking_id'     => $booking->id,
                    'customer_id'    => $booking->customer_id,
                    'mua_id'         => $booking->mua_id,
                    'grand_total'    => $booking->grand_total,
                    'payment_status' => $booking->payment_status,
                    'job_status'     => $booking->job_status,
                ]);

                return $booking;
            });

            Log::channel('bookings')->info('BOOKING_STORE_SUCCESS', $ctx + [
                'booking_id' => $result->id,
            ]);

            return response()->json([
                'message' => 'Booking (invoice) created',
                'data'    => $result
            ], 201);
        } catch (\Throwable $e) {
            Log::channel('bookings')->error('BOOKING_STORE_ERROR', $ctx + [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to create booking'], 500);
        }
    }

    public function show(Request $req, $offeringId)
    {
        $t0 = microtime(true);
        $with = $req->query('include'); // e.g. ?include=mua

        try {
            // Jika user minta include relasi, pakai eager load (opsi “B” sebagai fallback),
            // sekaligus tetap flatten 'mua_name' & 'mua_photo' agar FE bisa langsung pakai.
            if ($with === 'mua' || $req->boolean('include_mua')) {
                $off = Offering::with(['mua'])->findOrFail($offeringId);

                // flatten agar konsisten dengan Opsi A
                $off->setAttribute('mua_name', optional($off->mua)->name);
                $off->setAttribute('mua_photo', optional($off->mua)->photo_url);

                Log::channel('offerings')->info('OFFERING_SHOW_WITH_REL', [
                    'offering_id' => $offeringId,
                    'ms' => (int)((microtime(true)-$t0)*1000),
                ]);

                return response()->json(['data' => $off], 200);
            }

            // Default: Opsi A (LEFT JOIN ke profiles) untuk dapat 'mua_name' & 'mua_photo'
            $row = Offering::query()
                ->leftJoin('profiles as p', 'p.id', '=', 'offerings.mua_id')
                ->where('offerings.id', $offeringId)
                ->select([
                    'offerings.*',
                    'p.name as mua_name',
                    'p.photo_url as mua_photo',
                ])
                ->firstOrFail();

            Log::channel('offerings')->info('OFFERING_SHOW', [
                'offering_id' => $offeringId,
                'ms' => (int)((microtime(true)-$t0)*1000),
            ]);

            return response()->json(['data' => $row], 200);

        } catch (\Throwable $e) {
            Log::channel('offerings')->error('OFFERING_SHOW_ERROR', [
                'offering_id' => $offeringId,
                'error' => $e->getMessage(),
                'ms' => (int)((microtime(true)-$t0)*1000),
            ]);
            throw $e;
        }
    }

    public function update(Request $req, Booking $booking)
    {
        $ctx = $this->ctx($req, ['booking_id' => $booking->id]);
        Log::channel('bookings')->info('BOOKING_UPDATE_VALIDATE_START', $ctx);

        try {
            $data = $req->validate([
                'offering_id'      => ['nullable','integer','exists:offerings,id'],
                'booking_date'     => ['nullable','date_format:Y-m-d'],
                'booking_time'     => ['nullable','date_format:H:i'],
                'service_type'     => ['nullable', Rule::in(['home_service','studio'])],
                'location_address' => ['nullable','string','max:500'],
                'notes'            => ['nullable','string','max:1000'],

                'invoice_date'     => ['nullable','date'],
                'due_date'         => ['nullable','date','after_or_equal:invoice_date'],

                'amount'           => ['nullable','numeric'],
                'selected_add_ons' => ['nullable','array'],
                'selected_add_ons.*.name'  => ['required_with:selected_add_ons','string','max:100'],
                'selected_add_ons.*.price' => ['required_with:selected_add_ons','numeric'],
                'discount_amount'  => ['nullable','numeric'],
                'tax'              => ['nullable','numeric'],

                'payment_method'   => ['nullable','string','max:50'],
                'payment_status'   => ['nullable', Rule::in(['unpaid','partial','paid','refunded','void'])],
                'job_status'       => ['nullable', Rule::in(['pending','confirmed','in_progress','completed','cancelled'])],
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            Log::channel('bookings')->warning('BOOKING_UPDATE_VALIDATE_FAIL', $ctx + [
                'errors' => $ve->errors(),
            ]);
            throw $ve;
        }

        Log::channel('bookings')->info('BOOKING_UPDATE_DB_START', $ctx);

        try {
            $updated = DB::transaction(function () use ($booking, $data, $ctx) {
                foreach ($data as $k => $v) {
                    if (in_array($k, ['booking_date','invoice_date','due_date']) && !empty($v)) {
                        $booking->setAttribute($k, Carbon::parse($v));
                    } else {
                        $booking->setAttribute($k, $v);
                    }
                }

                $needRecalc = collect(['amount','selected_add_ons','discount_amount','tax'])
                    ->some(fn($k) => array_key_exists($k, $data));

                if ($needRecalc) {
                    $addOns = $booking->getAttribute('selected_add_ons');
                    if (is_string($addOns)) {
                        $decoded = json_decode($addOns, true);
                        $addOns = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
                    }
                    $booking->computeTotals($addOns);
                }

                $booking->save();

                Log::channel('bookings')->info('BOOKING_UPDATE_DB_SAVED', $ctx + [
                    'payment_status' => $booking->payment_status,
                    'job_status'     => $booking->job_status,
                    'grand_total'    => $booking->grand_total,
                ]);

                return $booking;
            });

            Log::channel('bookings')->info('BOOKING_UPDATE_SUCCESS', $ctx);

            return response()->json([
                'message' => 'Booking updated',
                'data'    => $updated
            ]);
        } catch (\Throwable $e) {
            Log::channel('bookings')->error('BOOKING_UPDATE_ERROR', $ctx + [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to update booking'], 500);
        }
    }

    /** Mulai pekerjaan oleh MUA */
    public function markInProgress(Booking $booking)
    {
        Log::channel('bookings')->info('BOOKING_MARK_IN_PROGRESS', [
            'booking_id' => $booking->id,
            'before'     => [
                'job_status'     => $booking->job_status,
                'payment_status' => $booking->payment_status,
            ],
        ]);

        $booking->setAttribute('job_status', 'in_progress');
        $booking->save();

        Log::channel('bookings')->info('BOOKING_MARK_IN_PROGRESS_SUCCESS', [
            'booking_id' => $booking->id,
            'after'      => [
                'job_status'     => $booking->job_status,
                'payment_status' => $booking->payment_status,
            ],
        ]);

        return response()->json([
            'message' => 'Job in progress',
            'data'    => $booking
        ]);
    }

    /** MUA menekan tombol "Selesai" */
    public function markComplete(Request $request, Booking $booking)
    {
        $rid = (string) Str::uuid();

        Log::channel('bookings')->info('BOOKING_MARK_COMPLETE_ATTEMPT', [
            'rid'        => $rid,
            'booking_id' => $booking->id,
            'before'     => [
                'status'         => $booking->status,
                'job_status'     => $booking->job_status,
                'payment_status' => $booking->payment_status,
                'paid_at'        => $booking->paid_at,
            ],
            'by'         => optional($request->user())->id,
            'ip'         => $request->ip(),
        ]);

        try {
            $updated = DB::transaction(function () use ($booking) {
                $booking->job_status = 'completed';
                $booking->status     = 'completed';

                if (!in_array($booking->payment_status, ['paid', 'refunded', 'void'], true)) {
                    $booking->payment_status = 'paid';
                }
                if ($booking->payment_status === 'paid' && empty($booking->paid_at)) {
                    $booking->paid_at = Carbon::now();
                }

                $booking->save();
                return $booking->fresh();
            });

            Log::channel('bookings')->info('BOOKING_MARK_COMPLETE_SUCCESS', [
                'rid'        => $rid,
                'booking_id' => $booking->id,
                'after'      => [
                    'status'         => $updated->status,
                    'job_status'     => $updated->job_status,
                    'payment_status' => $updated->payment_status,
                    'paid_at'        => $updated->paid_at,
                ],
            ]);

            return response()->json([
                'message' => 'Booking completed',
                'data'    => $updated,
            ]);
        } catch (\Throwable $e) {
            Log::channel('bookings')->error('BOOKING_MARK_COMPLETE_ERROR', [
                'rid'        => $rid,
                'booking_id' => $booking->id,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Gagal menandai selesai'], 500);
        }
    }

    /**
     * Catat pembayaran manual sederhana.
     */
    public function recordPayment(Request $req, Booking $booking)
    {
        $ctx = $this->ctx($req, ['booking_id' => $booking->id]);

        try {
            $payload = $req->validate([
                'amount'  => ['required','numeric','min:0'],
                'paid_at' => ['nullable','date'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            Log::channel('bookings')->warning('BOOKING_RECORD_PAYMENT_VALIDATE_FAIL', $ctx + [
                'errors' => $ve->errors(),
            ]);
            throw $ve;
        }

        $grandTotal = (float) ($booking->getAttribute('grand_total') ?? 0);
        $payAmount  = (float) $payload['amount'];

        if (!empty($payload['paid_at'])) {
            $booking->setAttribute('paid_at', Carbon::parse($payload['paid_at']));
        } else {
            $booking->setAttribute('paid_at', Carbon::now());
        }

        if ($grandTotal > 0 && $payAmount + 0.001 >= $grandTotal) {
            $booking->setAttribute('payment_status', 'paid');
        } elseif ($payAmount > 0) {
            $booking->setAttribute('payment_status', 'partial');
        } else {
            $booking->setAttribute('payment_status', 'unpaid');
        }

        $booking->save();

        Log::channel('bookings')->info('BOOKING_RECORD_PAYMENT_SAVED', $ctx + [
            'grand_total' => $grandTotal,
            'paid_amount' => $payAmount,
            'payment_status' => $booking->payment_status,
        ]);

        return response()->json([
            'message' => 'Payment recorded',
            'data'    => $booking
        ]);
    }

    public function getUserBookings(Request $request, string $userId)
    {
        $ctx = $this->ctx($request, ['query_user_id' => $userId]);

        $auth = $request->user();
        if (!$auth) {
            Log::channel('bookings')->warning('GET_USER_BOOKINGS_UNAUTH', $ctx);
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        if ((string) $auth->id !== (string) $userId) {
            Log::channel('bookings')->warning('GET_USER_BOOKINGS_FORBIDDEN', $ctx + [
                'auth_id' => $auth->id,
            ]);
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $perPage = min((int) $request->query('per_page', 10), 100);
            $status  = $request->query('status');

            $q = DB::table('bookings as b')
                ->join('profiles as mp', 'mp.id', '=', 'b.mua_id')
                ->leftJoin('offerings as o', 'o.id', '=', 'b.offering_id')
                ->where('b.customer_id', $userId)
                ->select([
                    'b.id',
                    'b.booking_date',
                    'b.booking_time',
                    'b.status',
                    'b.payment_status',
                    'b.grand_total',
                    'b.invoice_number',
                    'o.name_offer',
                    'o.price as offering_price',
                    'mp.name as mua_name',
                    'mp.photo_url as mua_photo',
                ])
                ->orderByDesc('b.created_at');

            if ($status) {
                $statuses = is_array($status) ? $status : array_map('trim', explode(',', $status));
                $q->whereIn('b.status', $statuses);
            }

            $pg = $q->paginate($perPage);

            Log::channel('bookings')->info('GET_USER_BOOKINGS_SUCCESS', $ctx + [
                'count'   => $pg->count(),
                'perPage' => $perPage,
                'page'    => $pg->currentPage(),
            ]);

            return response()->json($pg);
        } catch (\Throwable $e) {
            Log::channel('bookings')->error('GET_USER_BOOKINGS_ERROR', $ctx + [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to fetch user bookings'], 500);
        }
    }
}
