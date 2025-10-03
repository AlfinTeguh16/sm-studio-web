<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function index(Request $req)
    {
        $q = Booking::query();

        if ($req->filled('mua_id')) {
            $q->where('mua_id', $req->string('mua_id'));
        }
        if ($req->filled('customer_id')) {
            $q->where('customer_id', $req->string('customer_id'));
        }
        if ($req->filled('job_status')) {
            $q->where('job_status', $req->string('job_status'));
        }
        if ($req->filled('payment_status')) {
            $q->where('payment_status', $req->string('payment_status'));
        }

        $data = $q->latest('id')->paginate($req->integer('per_page', 20));
        return response()->json($data);
    }

    public function store(Request $req)
    {
        $data = $req->validate([
            'customer_id'      => ['required','uuid'],
            'mua_id'           => ['required','uuid'],
            'offering_id'      => ['nullable','integer','exists:offerings,id'],
            'booking_date'     => ['required','date_format:Y-m-d'],
            'booking_time'     => ['required','date_format:H:i'],
            'service_type'     => ['required', Rule::in(['home_service','studio'])],
            'location_address' => ['nullable','string','max:500'],
            'notes'            => ['nullable','string','max:1000'],

            // invoice meta (opsional)
            'invoice_date'     => ['nullable','date'],
            'due_date'         => ['nullable','date','after_or_equal:invoice_date'],

            // pricing
            'amount'           => ['nullable','numeric'],
            'selected_add_ons' => ['nullable','array'],
            'selected_add_ons.*.name'  => ['required_with:selected_add_ons','string','max:100'],
            'selected_add_ons.*.price' => ['required_with:selected_add_ons','numeric'],
            'discount_amount'  => ['nullable','numeric'],
            'tax'              => ['nullable','numeric'], // persen (legacy)

            // payment (manual)
            'payment_method'   => ['nullable','string','max:50'],
        ]);

        return DB::transaction(function () use ($data) {
            $booking = new Booking();

            // set via setAttribute agar aman dari linter
            foreach ($data as $k => $v) {
                // cast tanggal dengan Carbon bila perlu
                if (in_array($k, ['booking_date','invoice_date','due_date']) && !empty($v)) {
                    $booking->setAttribute($k, Carbon::parse($v));
                } else {
                    $booking->setAttribute($k, $v);
                }
            }

            // default yang aman
            if (empty($booking->getAttribute('payment_status'))) {
                $booking->setAttribute('payment_status', 'unpaid');
            }
            if (empty($booking->getAttribute('job_status'))) {
                $booking->setAttribute('job_status', 'pending');
            }
            if (empty($booking->getAttribute('invoice_date'))) {
                $booking->setAttribute('invoice_date', Carbon::now());
            }

            // hitung total (model sudah handle & simpan dengan setAttribute)
            $booking->computeTotals($booking->getAttribute('selected_add_ons'));

            $booking->save();

            return response()->json([
                'message' => 'Booking (invoice) created',
                'data'    => $booking
            ], 201);
        });
    }

    public function show(Booking $booking)
    {
        return response()->json($booking);
    }

    public function update(Request $req, Booking $booking)
    {
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

        return DB::transaction(function () use ($booking, $data) {
            foreach ($data as $k => $v) {
                if (in_array($k, ['booking_date','invoice_date','due_date']) && !empty($v)) {
                    $booking->setAttribute($k, Carbon::parse($v));
                } else {
                    $booking->setAttribute($k, $v);
                }
            }

            // re-calc jika ada field harga terkait
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

            return response()->json([
                'message' => 'Booking updated',
                'data'    => $booking
            ]);
        });
    }

    /** Mulai pekerjaan oleh MUA */
    public function markInProgress(Booking $booking)
    {
        $booking->setAttribute('job_status', 'in_progress');
        $booking->save();

        return response()->json([
            'message' => 'Job in progress',
            'data'    => $booking
        ]);
    }

    /** MUA menekan tombol "Selesai" */
    public function markComplete(Booking $booking)
    {
        $booking->setAttribute('job_status', 'completed');
        $booking->save();

        return response()->json([
            'message' => 'Job completed',
            'data'    => $booking
        ]);
    }

    /**
     * (Opsional) Catat pembayaran manual sederhana.
     * Body: { "amount": 100000, "paid_at": "2025-10-04 14:15:00" }
     * Logika: jika total pembayaran >= grand_total → payment_status = paid, else partial.
     */
    public function recordPayment(Request $req, Booking $booking)
    {
        $payload = $req->validate([
            'amount'  => ['required','numeric','min:0'],
            'paid_at' => ['nullable','date'],
        ]);

        // ambil total paid sebelumnya dari kolom paid_at? (Tidak ada log payments di tabel terpisah)
        // Untuk versi simpel, kita cuma update status berdasar amount sekali ini:
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

        return response()->json([
            'message' => 'Payment recorded',
            'data'    => $booking
        ]);
    }
}
