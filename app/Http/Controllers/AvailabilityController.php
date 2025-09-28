<?php

namespace App\Http\Controllers;

use App\Models\{Availability, Booking, Profile};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class AvailabilityController extends Controller
{
    /**
     * GET /availability
     * Query:
     *  - muaId (uuid)            : filter MUA tertentu
     *  - date (Y-m-d)            : 1 tanggal
     *  - date_from, date_to      : rentang tanggal (inklusif)
     * Return: daftar availability mentah (tanpa mengurangi booking)
     */
    public function index(Request $req)
    {
        $q = Availability::query();

        if ($req->filled('muaId')) $q->where('mua_id', $req->query('muaId'));
        if ($req->filled('date'))  $q->whereDate('available_date', $req->query('date'));
        if ($req->filled('date_from')) $q->whereDate('available_date', '>=', $req->query('date_from'));
        if ($req->filled('date_to'))   $q->whereDate('available_date', '<=', $req->query('date_to'));

        return response()->json($q->orderBy('available_date')->get());
    }

    /**
     * POST /availability
     * Body: { mua_id, available_date(Y-m-d), time_slots: string[] }
     * Upsert 1 hari penuh (replace times).
     */
    public function upsert(Request $req)
    {
        $data = $req->validate([
            'mua_id'         => ['required','uuid'],
            'available_date' => ['required','date_format:Y-m-d'],
            'time_slots'     => ['required','array','min:0'],
            'time_slots.*'   => ['string'],
        ]);

        $this->guardMuaOrAdmin($req->user()->id, $data['mua_id']);

        $slots = $this->normalizeTimes($data['time_slots']);
        $avail = Availability::updateOrCreate(
            ['mua_id' => $data['mua_id'], 'available_date' => $data['available_date']],
            ['time_slots' => $slots]
        );

        return response()->json($avail);
    }

    /**
     * PATCH /availability/slots/add
     * Body: { mua_id, date(Y-m-d), time (HH:MM) | times: string[] }
     * Tambah 1/lebih slot ke hari tsb (merge unique + sort).
     */
    public function addSlot(Request $req)
    {
        $data = $req->validate([
            'mua_id' => ['required','uuid'],
            'date'   => ['required','date_format:Y-m-d'],
            'time'   => ['nullable','string'],
            'times'  => ['nullable','array'],
            'times.*'=> ['string'],
        ]);

        $this->guardMuaOrAdmin($req->user()->id, $data['mua_id']);

        $toAdd = [];
        if (!empty($data['time']))  $toAdd[] = $data['time'];
        if (!empty($data['times'])) $toAdd = array_merge($toAdd, $data['times']);
        $toAdd = $this->normalizeTimes($toAdd);

        $avail = Availability::firstOrNew(['mua_id' => $data['mua_id'], 'available_date' => $data['date']]);
        $current = $avail->time_slots ?? [];
        $merged = $this->mergeUniqueSorted($current, $toAdd);

        $avail->time_slots = $merged;
        $avail->save();

        return response()->json($avail);
    }

    /**
     * PATCH /availability/slots/remove
     * Body: { mua_id, date(Y-m-d), time (HH:MM) | times: string[] }
     * Hapus 1/lebih slot dari hari tsb (menyisakan array kosong bila habis).
     */
    public function removeSlot(Request $req)
    {
        $data = $req->validate([
            'mua_id' => ['required','uuid'],
            'date'   => ['required','date_format:Y-m-d'],
            'time'   => ['nullable','string'],
            'times'  => ['nullable','array'],
            'times.*'=> ['string'],
        ]);

        $this->guardMuaOrAdmin($req->user()->id, $data['mua_id']);

        $toRemove = [];
        if (!empty($data['time']))  $toRemove[] = $data['time'];
        if (!empty($data['times'])) $toRemove = array_merge($toRemove, $data['times']);
        $toRemove = $this->normalizeTimes($toRemove);

        $avail = Availability::firstOrNew(['mua_id' => $data['mua_id'], 'available_date' => $data['date']]);
        $current = $avail->time_slots ?? [];

        $remain = array_values(array_diff($current, $toRemove));
        $avail->time_slots = $remain;
        $avail->save();

        return response()->json($avail);
    }

    /**
     * DELETE /availability/day
     * Query: ?muaId=uuid&date=Y-m-d
     * Hapus record availability untuk satu hari.
     */
    public function deleteDay(Request $req)
    {
        $req->validate([
            'muaId' => ['required','uuid'],
            'date'  => ['required','date_format:Y-m-d'],
        ]);

        $this->guardMuaOrAdmin($req->user()->id, $req->query('muaId'));

        Availability::where('mua_id', $req->query('muaId'))
            ->whereDate('available_date', $req->query('date'))
            ->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /availability/bulk
     * Body: { items: [{mua_id, date(Y-m-d), time_slots: string[]}, ...] } (max 366)
     * Upsert banyak hari sekaligus.
     */
    public function bulkUpsert(Request $req)
    {
        $data = $req->validate([
            'items'             => ['required','array','min:1','max:366'],
            'items.*.mua_id'    => ['required','uuid'],
            'items.*.date'      => ['required','date_format:Y-m-d'],
            'items.*.time_slots'=> ['required','array'],
            'items.*.time_slots.*'=> ['string'],
        ]);

        $actorId = $req->user()->id;
        $actor = Profile::findOrFail($actorId);

        DB::transaction(function () use ($data, $actor, $actorId) {
            foreach ($data['items'] as $it) {
                if ($actor->role !== 'admin' && $it['mua_id'] !== $actorId) {
                    abort(403, 'Forbidden');
                }
                $slots = $this->normalizeTimes($it['time_slots']);
                Availability::updateOrCreate(
                    ['mua_id' => $it['mua_id'], 'available_date' => $it['date']],
                    ['time_slots' => $slots]
                );
            }
        });

        return response()->json(['ok' => true]);
    }

    /**
     * POST /availability/recurring
     * Body:
     *  {
     *    "mua_id": "uuid",
     *    "date_from": "2025-10-01",
     *    "date_to":   "2025-10-31",
     *    "template": {
     *      "mon": ["09:00","13:00"],
     *      "tue": ["09:00","13:00"],
     *      "wed": [],
     *      "thu": ["10:00"],
     *      "fri": ["10:00"],
     *      "sat": ["08:00","14:00"],
     *      "sun": []
     *    }
     *  }
     * Membentuk jadwal mingguan untuk rentang tanggal.
     */
    public function recurring(Request $req)
    {
        $data = $req->validate([
            'mua_id'    => ['required','uuid'],
            'date_from' => ['required','date_format:Y-m-d'],
            'date_to'   => ['required','date_format:Y-m-d','after_or_equal:date_from'],
            'template'  => ['required','array'],
        ]);

        $this->guardMuaOrAdmin($req->user()->id, $data['mua_id']);

        $mapDay = [
            'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 0,
        ];

        DB::transaction(function () use ($data, $mapDay) {
            $period = CarbonPeriod::create($data['date_from'], $data['date_to']);
            foreach ($period as $date) {
                /** @var Carbon $date */
                $dow = (int)$date->dayOfWeek; // 0 (Sun) .. 6 (Sat)
                $key = array_search($dow, $mapDay, true);
                if ($key === false) continue;

                $slots = $data['template'][$key] ?? null;
                if (!is_array($slots)) continue;

                $norm = $this->normalizeTimes($slots);
                Availability::updateOrCreate(
                    ['mua_id' => $data['mua_id'], 'available_date' => $date->toDateString()],
                    ['time_slots' => $norm]
                );
            }
        });

        return response()->json(['ok' => true]);
    }

    /**
     * GET /availability/free
     * Query:
     *  - muaId (uuid) [wajib]
     *  - date (Y-m-d)  ATAU date_from & date_to
     * Output:
     *  - Mode single date  : { date, free_slots: [...] }
     *  - Mode date range   : [ { date, free_slots: [...] }, ... ]
     */
    public function free(Request $req)
    {
        $req->validate([
            'muaId'     => ['required','uuid'],
            'date'      => ['nullable','date_format:Y-m-d'],
            'date_from' => ['nullable','date_format:Y-m-d'],
            'date_to'   => ['nullable','date_format:Y-m-d'],
        ]);

        $muaId = $req->query('muaId');
        $date  = $req->query('date');
        $from  = $req->query('date_from');
        $to    = $req->query('date_to');

        if (!$date && !$from) return response()->json(['error'=>'date atau date_from wajib'], 422);
        if ($from && !$to) $to = $from;

        // Ambil availability & keying dengan Y-m-d aman
        $av = Availability::where('mua_id', $muaId)
            ->when($date, fn($q)=>$q->whereDate('available_date', $date))
            ->when(!$date, fn($q)=>$q->whereBetween('available_date', [$from, $to]))
            ->get()
            ->keyBy(fn($a) => $this->ymd($a->available_date));

        // Ambil booking aktif dan group by tanggal aman
        $booked = Booking::where('mua_id', $muaId)
            ->whereIn('status', ['pending','confirmed'])
            ->when($date, fn($q)=>$q->whereDate('booking_date', $date))
            ->when(!$date, fn($q)=>$q->whereBetween('booking_date', [$from, $to]))
            ->get()
            ->groupBy(fn($b) => $this->ymd($b->booking_date));

        if ($date) {
            $base = ($av->get($date)->time_slots ?? []);
            $busy = $booked->has($date) ? $booked->get($date)->pluck('booking_time')->all() : [];
            $free = array_values(array_diff($base, $busy));
            sort($free);
            return response()->json(['date' => $date, 'free_slots' => $free]);
        }

        // rentang tanggal
        $out = [];
        $start = new \DateTimeImmutable($from);
        $end   = new \DateTimeImmutable($to);
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $ds   = $d->format('Y-m-d');
            $base = ($av->get($ds)->time_slots ?? []);
            $busy = $booked->has($ds) ? $booked->get($ds)->pluck('booking_time')->all() : [];
            $free = array_values(array_diff($base, $busy));
            sort($free);
            $out[] = ['date' => $ds, 'free_slots' => $free];
        }

        return response()->json($out);
    }

    /** Terima Carbon|string|DateTimeInterface → kembalikan 'Y-m-d' */
    private function ymd($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        // fallback string 'YYYY-MM-DD...' → ambil 10 char pertama
        return substr((string)$value, 0, 10);
    }


    /**
     * GET /availability/check
     * Query: ?muaId=uuid&date=Y-m-d&time=HH:MM
     * Cek slot tersedia (ada di availability & belum dibooking pending/confirmed).
     */
    public function check(Request $req)
    {
        $req->validate([
            'muaId' => ['required','uuid'],
            'date'  => ['required','date_format:Y-m-d'],
            'time'  => ['required','string'],
        ]);

        $time = $this->normalizeTimes([$req->query('time')])[0];

        $avail = Availability::where('mua_id', $req->query('muaId'))
            ->whereDate('available_date', $req->query('date'))
            ->first();

        if (!$avail || !in_array($time, $avail->time_slots ?? [])) {
            return response()->json(['available' => false, 'reason' => 'not_in_availability']);
        }

        $exists = Booking::where('mua_id', $req->query('muaId'))
            ->whereDate('booking_date', $req->query('date'))
            ->where('booking_time', $time)
            ->whereIn('status', ['pending','confirmed'])
            ->exists();

        return response()->json([
            'available' => !$exists,
            'reason'    => $exists ? 'already_booked' : 'ok'
        ]);
    }

    /* ====================== Helpers ====================== */

    private function guardMuaOrAdmin(string $actorId, string $muaId): void
    {
        $actor = Profile::findOrFail($actorId);
        if ($actor->role !== 'admin' && $actorId !== $muaId) {
            abort(403, 'Forbidden');
        }
    }

    private function normalizeTimes(array $times): array
    {
        $out = [];
        foreach ($times as $t) {
            $t = trim((string)$t);
            if (!preg_match('/^(\d{1,2}):(\d{1,2})$/', $t, $m)) {
                abort(422, "Format jam tidak valid: {$t}");
            }
            $h = (int)$m[1]; $i = (int)$m[2];
            if ($h < 0 || $h > 23 || $i < 0 || $i > 59) {
                abort(422, "Jam di luar rentang: {$t}");
            }
            $out[] = sprintf('%02d:%02d', $h, $i);
        }
        sort($out);
        return array_values(array_unique($out));
    }

    private function mergeUniqueSorted(array $a, array $b): array
    {
        $merged = array_values(array_unique(array_merge($a, $b)));
        sort($merged);
        return $merged;
    }
}
