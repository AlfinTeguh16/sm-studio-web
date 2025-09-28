<?php

namespace App\Http\Controllers;

use App\Models\{Offering, Profile};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OfferingController extends Controller
{
    /**
     * GET /offerings
     * Query (opsional):
     * - muaId=uuid
     * - makeup_type=string
     * - person_min, person_max
     * - price_min, price_max
     * - date_from, date_to (Y-m-d)
     * - has_collaboration=true|false
     * - q=keyword (cari di name_offer/collaboration)
     * - per_page=1..100 (default 20), page
     * - sort=created_at|price (default: created_at), dir=asc|desc (default: desc)
     */
    public function index(Request $req)
    {
        $per = (int) $req->query('per_page', 20);
        $per = max(1, min(100, $per));

        $sort = $req->query('sort', 'created_at');
        $dir  = $req->query('dir', 'desc');
        if (!in_array($sort, ['created_at','price'], true)) $sort = 'created_at';
        if (!in_array(strtolower($dir), ['asc','desc'], true)) $dir = 'desc';

        $q = Offering::query();

        if ($req->filled('muaId'))       $q->where('mua_id', $req->query('muaId'));
        if ($req->filled('makeup_type')) $q->where('makeup_type', $req->query('makeup_type'));

        if ($req->filled('person_min'))  $q->where('person', '>=', (int)$req->query('person_min'));
        if ($req->filled('person_max'))  $q->where('person', '<=', (int)$req->query('person_max'));

        if ($req->filled('price_min'))   $q->where('price', '>=', (float)$req->query('price_min'));
        if ($req->filled('price_max'))   $q->where('price', '<=', (float)$req->query('price_max'));

        if ($req->filled('date_from'))   $q->whereDate('date', '>=', $req->query('date_from'));
        if ($req->filled('date_to'))     $q->whereDate('date', '<=', $req->query('date_to'));

        if ($req->has('has_collaboration')) {
            $flag = filter_var($req->query('has_collaboration'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($flag === true)  $q->whereNotNull('collaboration')->whereNotNull('collaboration_price');
            if ($flag === false) $q->whereNull('collaboration')->whereNull('collaboration_price');
        }

        if ($req->filled('q')) {
            $kw = '%'.$req->query('q').'%';
            $q->where(function ($qq) use ($kw) {
                $qq->where('name_offer','like',$kw)->orWhere('collaboration','like',$kw);
            });
        }

        $data = $q->orderBy($sort, $dir)->paginate($per);
        return response()->json($data);
    }

    /**
     * GET /offerings/{offering}
     */
    public function show(Request $req, Offering $offering)
    {
        return response()->json($offering);
    }

    /**
     * GET /offerings/mine
     * List offering milik user login (MUA).
     */
    public function mine(Request $req)
    {
        $authId = $req->user()->id;
        return Offering::where('mua_id', $authId)
            ->orderByDesc('created_at')
            ->paginate((int)max(1, min(100, $req->query('per_page', 20))));
    }

    /**
     * POST /offerings
     * Body:
     *  - mua_id (uuid)
     *  - name_offer (string)
     *  - offer_pictures? (string[])  // array URL
     *  - makeup_type? (string)
     *  - person? (int >=1)
     *  - collaboration? (string|null) + collaboration_price? (numeric|null)
     *  - add_ons? (string[])         // bebas
     *  - date? (Y-m-d)
     *  - price (numeric)
     */
    public function store(Request $req)
    {
        $data = $req->validate([
            'mua_id'              => ['required','uuid'],
            'name_offer'          => ['required','string','max:255'],
            'offer_pictures'      => ['nullable','array'],
            'offer_pictures.*'    => ['string','max:1000'],
            'makeup_type'         => ['nullable','string','max:100'],
            'person'              => ['nullable','integer','min:1'],
            'collaboration'       => ['nullable','string','max:255'],
            'collaboration_price' => ['nullable','numeric','min:0'],
            'add_ons'             => ['nullable','array'],
            'add_ons.*'           => ['string','max:100'],
            'date'                => ['nullable','date_format:Y-m-d'],
            'price'               => ['required','numeric','min:0'],
        ]);

        $this->guardOwnerOrAdmin($req->user()->id, $data['mua_id']);

        // Enforce kolaborasi <-> harga kolaborasi
        if (empty($data['collaboration'])) {
            $data['collaboration'] = null;
            $data['collaboration_price'] = null;
        } else {
            if (!isset($data['collaboration_price'])) {
                return response()->json(['error'=>'collaboration_price wajib jika collaboration diisi'], 422);
            }
        }

        $data['offer_pictures'] = $this->normalizeArray($data['offer_pictures'] ?? [], 50);
        $data['add_ons']        = $this->normalizeArray($data['add_ons'] ?? [], 50);

        $off = Offering::create($data);
        return response()->json($off, 201);
    }

    /**
     * PUT/PATCH /offerings/{offering}
     * Partial update (replace field yang dikirim).
     * NB: offer_pictures & add_ons jika dikirim → REPLACE; untuk tambah/hapus gunakan endpoint khusus.
     */
    public function update(Request $req, Offering $offering)
    {
        $this->guardOwnerOrAdmin($req->user()->id, $offering->mua_id);

        $data = $req->validate([
            'name_offer'          => ['sometimes','string','max:255'],
            'offer_pictures'      => ['sometimes','array'],
            'offer_pictures.*'    => ['string','max:1000'],
            'makeup_type'         => ['sometimes','nullable','string','max:100'],
            'person'              => ['sometimes','integer','min:1'],
            'collaboration'       => ['sometimes','nullable','string','max:255'],
            'collaboration_price' => ['sometimes','nullable','numeric','min:0'],
            'add_ons'             => ['sometimes','array'],
            'add_ons.*'           => ['string','max:100'],
            'date'                => ['sometimes','nullable','date_format:Y-m-d'],
            'price'               => ['sometimes','numeric','min:0'],
        ]);

        // Aturan collab
        if (array_key_exists('collaboration', $data)) {
            if (empty($data['collaboration'])) {
                $data['collaboration'] = null;
                $data['collaboration_price'] = null; // kosongkan otomatis
            } else {
                if (!array_key_exists('collaboration_price', $data) && $offering->collaboration_price === null) {
                    return response()->json(['error'=>'collaboration_price wajib jika collaboration diisi'], 422);
                }
            }
        }

        if (array_key_exists('offer_pictures', $data)) {
            $data['offer_pictures'] = $this->normalizeArray($data['offer_pictures'] ?? [], 50);
        }
        if (array_key_exists('add_ons', $data)) {
            $data['add_ons'] = $this->normalizeArray($data['add_ons'] ?? [], 50);
        }

        $offering->fill($data)->save();
        return response()->json($offering->fresh());
    }

    /**
     * DELETE /offerings/{offering}
     */
    public function destroy(Request $req, Offering $offering)
    {
        $this->guardOwnerOrAdmin($req->user()->id, $offering->mua_id);
        $offering->delete();
        return response()->json(['ok'=>true]);
    }

    /**
     * PATCH /offerings/{offering}/pictures
     * Body:
     *  - mode=add|remove|replace (default: add)
     *  - pictures: string[]
     */
    public function pictures(Request $req, Offering $offering)
    {
        $this->guardOwnerOrAdmin($req->user()->id, $offering->mua_id);

        $data = $req->validate([
            'mode'      => ['nullable', Rule::in(['add','remove','replace'])],
            'pictures'  => ['required','array','min:1'],
            'pictures.*'=> ['string','max:1000'],
        ]);

        $mode = $data['mode'] ?? 'add';
        $pics = $this->normalizeArray($data['pictures'], 50);

        $current = $offering->offer_pictures ?? [];

        if ($mode === 'replace') {
            $offering->offer_pictures = $pics;
        } elseif ($mode === 'remove') {
            $offering->offer_pictures = array_values(array_diff($current, $pics));
        } else { // add/merge
            $offering->offer_pictures = $this->mergeUnique($current, $pics, 50);
        }

        $offering->save();
        return response()->json($offering->fresh());
    }

    /**
     * PATCH /offerings/{offering}/addons
     * Body:
     *  - mode=add|remove|replace (default: replace)
     *  - add_ons: string[]
     */
    public function addons(Request $req, Offering $offering)
    {
        $this->guardOwnerOrAdmin($req->user()->id, $offering->mua_id);

        $data = $req->validate([
            'mode'      => ['nullable', Rule::in(['add','remove','replace'])],
            'add_ons'   => ['required','array'],
            'add_ons.*' => ['string','max:100'],
        ]);

        $mode = $data['mode'] ?? 'replace';
        $add  = $this->normalizeArray($data['add_ons'], 50);

        $current = $offering->add_ons ?? [];

        if ($mode === 'replace') {
            $offering->add_ons = $add;
        } elseif ($mode === 'remove') {
            $offering->add_ons = array_values(array_diff($current, $add));
        } else { // add
            $offering->add_ons = $this->mergeUnique($current, $add, 50);
        }

        $offering->save();
        return response()->json($offering->fresh());
    }

    /**
     * POST /offerings/bulk
     * Body: { items: [ { ...payload seperti store()... }, ... ] } (max 100)
     * Non-admin hanya boleh menambahkan untuk dirinya sendiri.
     */
    public function bulkStore(Request $req)
    {
        $data = $req->validate([
            'items' => ['required','array','min:1','max:100'],
            'items.*.mua_id'              => ['required','uuid'],
            'items.*.name_offer'          => ['required','string','max:255'],
            'items.*.offer_pictures'      => ['nullable','array'],
            'items.*.offer_pictures.*'    => ['string','max:1000'],
            'items.*.makeup_type'         => ['nullable','string','max:100'],
            'items.*.person'              => ['nullable','integer','min:1'],
            'items.*.collaboration'       => ['nullable','string','max:255'],
            'items.*.collaboration_price' => ['nullable','numeric','min:0'],
            'items.*.add_ons'             => ['nullable','array'],
            'items.*.add_ons.*'           => ['string','max:100'],
            'items.*.date'                => ['nullable','date_format:Y-m-d'],
            'items.*.price'               => ['required','numeric','min:0'],
        ]);

        $actor = Profile::findOrFail($req->user()->id);

        $created = [];
        DB::transaction(function () use (&$created, $data, $actor) {
            foreach ($data['items'] as $it) {
                if ($actor->role !== 'admin' && $it['mua_id'] !== $actor->id) {
                    abort(403, 'Forbidden');
                }
                if (empty($it['collaboration'])) {
                    $it['collaboration'] = null;
                    $it['collaboration_price'] = null;
                } else {
                    if (!isset($it['collaboration_price'])) {
                        abort(422, 'collaboration_price wajib jika collaboration diisi');
                    }
                }
                $it['offer_pictures'] = $this->normalizeArray($it['offer_pictures'] ?? [], 50);
                $it['add_ons']        = $this->normalizeArray($it['add_ons'] ?? [], 50);

                $created[] = Offering::create($it);
            }
        });

        return response()->json($created, 201);
    }

    /* ====================== Helpers ====================== */

    private function guardOwnerOrAdmin(string $actorId, string $ownerId): void
    {
        $actor = Profile::findOrFail($actorId);
        if ($actor->role !== 'admin' && $actorId !== $ownerId) {
            abort(403, 'Forbidden');
        }
    }

    /** Normalisasi array string (trim, unique, limit) */
    private function normalizeArray(array $arr, int $limit = 50): array
    {
        $arr = array_values(array_filter(array_map(fn($s) => trim((string)$s), $arr), fn($s) => $s !== ''));
        $arr = array_values(array_unique($arr));
        if (count($arr) > $limit) $arr = array_slice($arr, 0, $limit);
        return $arr;
    }

    /** Merge dua array string lalu unique+limit */
    private function mergeUnique(array $a, array $b, int $limit = 50): array
    {
        $m = array_values(array_unique(array_merge($a, $b)));
        if (count($m) > $limit) $m = array_slice($m, 0, $limit);
        return $m;
    }
}
