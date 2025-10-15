<?php

namespace App\Http\Controllers;

use App\Models\{Offering, Profile};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Throwable;

class OfferingController extends Controller
{
    /** Utility: build context dasar utk log */
    private function baseCtx(Request $req, ?string $rid = null): array
    {
        return [
            'rid'      => $rid ?? (string) Str::uuid(),
            'ip'       => $req->ip(),
            'ua_hash'  => hash('sha256', (string) $req->userAgent()),
            'actorId'  => optional($req->user())->id,
            'route'    => optional($req->route())->uri(),
            'method'   => $req->method(),
        ];
    }

    /** Utility: ringkas array untuk log */
    private function briefArray(?array $arr, int $limit = 10): array
    {
        if (!is_array($arr)) return [];
        $out = array_slice($arr, 0, $limit);
        if (count($arr) > $limit) $out[] = '...+'.(count($arr)-$limit);
        return $out;
    }

    /** Utility: ringkas string */
    private function brief(?string $str, int $len = 120): ?string
    {
        if ($str === null) return null;
        $s = trim($str);
        return mb_strlen($s) > $len ? (mb_substr($s, 0, $len).'…') : $s;
    }

    /**
     * GET /offerings
     */
    public function index(Request $req)
    {
        $t0  = microtime(true);
        $ctx = $this->baseCtx($req);

        Log::channel('offerings')->info('OFFERINGS_INDEX_ATTEMPT', $ctx + [
            'query' => [
                'muaId'            => $req->query('muaId'),
                'makeup_type'      => $req->query('makeup_type'),
                'person_min'       => $req->query('person_min'),
                'person_max'       => $req->query('person_max'),
                'price_min'        => $req->query('price_min'),
                'price_max'        => $req->query('price_max'),
                'has_collaboration'=> $req->query('has_collaboration'),
                'q'                => $this->brief($req->query('q')),
                'per_page'         => $req->query('per_page'),
                'sort'             => $req->query('sort'),
                'dir'              => $req->query('dir'),
            ],
        ]);

        try {
            // --- Paging & sorting guard ---
            $per = (int) $req->query('per_page', 20);
            $per = max(1, min(100, $per));

            $sort = $req->query('sort', 'created_at');
            $dir  = strtolower($req->query('dir', 'desc'));
            if (!in_array($sort, ['created_at','price'], true)) {
                $sort = 'created_at';
            }
            if (!in_array($dir, ['asc','desc'], true)) {
                $dir = 'desc';
            }

            // --- Base query: JOIN profiles + select alias ---
            $q = Offering::query()
                ->leftJoin('profiles as p', 'p.id', '=', 'offerings.mua_id')
                ->select([
                    'offerings.*',
                    'p.name as mua_name',
                    'p.photo_url as mua_photo',
                ]);

            // --- Filters ---
            if ($req->filled('muaId')) {
                $q->where('offerings.mua_id', $req->query('muaId'));
            }
            if ($req->filled('makeup_type')) {
                $q->where('offerings.makeup_type', $req->query('makeup_type'));
            }

            if ($req->filled('person_min')) {
                $q->where('offerings.person', '>=', (int) $req->query('person_min'));
            }
            if ($req->filled('person_max')) {
                $q->where('offerings.person', '<=', (int) $req->query('person_max'));
            }

            if ($req->filled('price_min')) {
                $q->where('offerings.price', '>=', (float) $req->query('price_min'));
            }
            if ($req->filled('price_max')) {
                $q->where('offerings.price', '<=', (float) $req->query('price_max'));
            }

            if ($req->has('has_collaboration')) {
                $flag = filter_var($req->query('has_collaboration'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($flag === true) {
                    $q->whereNotNull('offerings.collaboration')
                    ->whereNotNull('offerings.collaboration_price');
                } elseif ($flag === false) {
                    $q->whereNull('offerings.collaboration')
                    ->whereNull('offerings.collaboration_price');
                }
            }

            if ($req->filled('q')) {
                $kw = '%'.$req->query('q').'%';
                $q->where(function ($qq) use ($kw) {
                    $qq->where('offerings.name_offer', 'like', $kw)
                    ->orWhere('offerings.collaboration', 'like', $kw)
                    ->orWhere('p.name', 'like', $kw); // cari di nama MUA
                });
            }

            // --- Order & paginate ---
            $data = $q->orderBy('offerings.'.$sort, $dir)->paginate($per);

            Log::channel('offerings')->info('OFFERINGS_INDEX_SUCCESS', $ctx + [
                'count'   => $data->count(),
                'perPage' => $data->perPage(),
                'page'    => $data->currentPage(),
                'ms'      => (int) ((microtime(true) - $t0) * 1000),
            ]);

            // Catatan: hasil JSON tiap item akan punya 'mua_name' & 'mua_photo'
            return response()->json($data);

        } catch (Throwable $e) {
            Log::channel('offerings')->error('OFFERINGS_INDEX_ERROR', $ctx + [
                'error' => $e->getMessage(),
                'ms'    => (int) ((microtime(true) - $t0) * 1000),
            ]);
            throw $e;
        }
    }


    /**
     * GET /offerings/{offering}
     */
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

        } catch (Throwable $e) {
            Log::channel('offerings')->error('OFFERING_SHOW_ERROR', [
                'offering_id' => $offeringId,
                'error' => $e->getMessage(),
                'ms' => (int)((microtime(true)-$t0)*1000),
            ]);
            throw $e;
        }
    }

    /**
     * GET /offerings/mine
     */
    public function mine(Request $req)
    {
        $t0  = microtime(true);
        $ctx = $this->baseCtx($req);
        Log::channel('offerings')->info('OFFERINGS_MINE_ATTEMPT', $ctx + [
            'per_page' => $req->query('per_page')
        ]);

        try {
            $authId = $req->user()->id;
            $data = Offering::where('mua_id', $authId)
                ->orderByDesc('created_at')
                ->paginate((int)max(1, min(100, $req->query('per_page', 20))));

            Log::channel('offerings')->info('OFFERINGS_MINE_SUCCESS', $ctx + [
                'count' => $data->count(),
                'ms'    => (int) ((microtime(true) - $t0)*1000),
            ]);
            return $data;
        } catch (Throwable $e) {
            Log::channel('offerings')->error('OFFERINGS_MINE_ERROR', $ctx + [
                'error' => $e->getMessage(),
                'ms'    => (int) ((microtime(true) - $t0)*1000),
            ]);
            throw $e;
        }
    }

    /**
     * POST /offerings
     */
    public function store(Request $req)
    {
        $t0  = microtime(true);
        $ctx = $this->baseCtx($req);
        Log::channel('offerings')->info('OFFERINGS_STORE_ATTEMPT', $ctx);

        try {
            $data = $req->validate([
                'mua_id'              => ['required','uuid'],
                'name_offer'          => ['required','string','max:255'],
                'offer_pictures'      => ['nullable','array'],
                'offer_pictures.*'    => ['string','max:1000'],
                'makeup_type'         => ['nullable','string','max:100'],
                'collaboration'       => ['nullable','string','max:255'],
                'collaboration_price' => ['nullable','numeric','min:0'],
                'add_ons'             => ['nullable','array'],
                'add_ons.*'           => ['string','max:100'],
                'price'               => ['required','numeric','min:0'],
            ]);

            $this->guardOwnerOrAdmin($req->user()->id, $data['mua_id']);

            if (empty($data['collaboration'])) {
                $data['collaboration'] = null;
                $data['collaboration_price'] = null;
            } else {
                if (!isset($data['collaboration_price'])) {
                    Log::channel('offerings')->warning('OFFERINGS_STORE_BAD_COMBO', $ctx);
                    return response()->json(['error'=>'collaboration_price wajib jika collaboration diisi'], 422);
                }
            }

            $data['offer_pictures'] = $this->normalizeArray($data['offer_pictures'] ?? [], 50);
            $data['add_ons']        = $this->normalizeArray($data['add_ons'] ?? [], 50);

            $off = Offering::create($data);

            Log::channel('offerings')->info('OFFERINGS_STORE_SUCCESS', $ctx + [
                'offeringId' => $off->id,
                'ms'         => (int) ((microtime(true) - $t0)*1000),
            ]);

            return response()->json($off, 201);
        } catch (Throwable $e) {
            Log::channel('offerings')->error('OFFERINGS_STORE_ERROR', $ctx + [
                'error' => $e->getMessage(),
                'ms'    => (int) ((microtime(true) - $t0)*1000),
            ]);
            throw $e;
        }
    }

    /**
     * PUT/PATCH /offerings/{offering}
     */
    public function update(Request $req, Offering $offering)
{
    $t0  = microtime(true);
    $ctx = $this->baseCtx($req) + ['offeringId' => $offering->id];
    Log::channel('offerings')->info('OFFERINGS_UPDATE_ATTEMPT', $ctx);

    try {
        // === Auth fail-fast agar tidak "Attempt to read property 'id' on null"
        if (!$req->user()) {
            Log::channel('offerings')->warning('UNAUTHENTICATED', $ctx);
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // === Guard owner/admin
        $this->guardOwnerOrAdmin($req->user()->id, $offering->mua_id);

        // === Validasi
        $data = $req->validate([
            'name_offer'          => ['sometimes','string','max:255'],
            'makeup_type'         => ['sometimes','nullable','string','max:100'],
            'collaboration'       => ['sometimes','nullable','string','max:255'],
            'collaboration_price' => ['sometimes','nullable','numeric','min:0'],
            'price'               => ['sometimes','nullable','numeric','min:0'],

            // JSON paths
            'offer_pictures'      => ['sometimes','array'],
            'offer_pictures.*'    => ['string','max:1000'],

            // Multipart files
            'offer_images'   => ['sometimes'],
            'offer_images.*' => ['file','image','max:5120'],
            'offer_image'         => ['sometimes','file','image','max:4096'],

            // Add-ons
            'add_ons'             => ['sometimes','array'],
            'add_ons.*'           => ['string','max:100'],
        ]);

        Log::channel('offerings')->info('OFFERINGS_UPDATE_VALIDATED', $ctx + [
            'fields' => array_keys($data),
            'pics_in_payload' => isset($data['offer_pictures']) ? count((array) $data['offer_pictures']) : 0,
            'files_multipart'  => [
                'offer_images' => $req->hasFile('offer_images') ? (is_array($req->file('offer_images')) ? count(Arr::flatten($req->file('offer_images'))) : 1) : 0,
                'offer_image'  => $req->hasFile('offer_image') ? 1 : 0,
            ],
            'all_files_keys' => array_keys($req->allFiles()),
        ]);

        // === Aturan kolaborasi
        if ($req->has('collaboration')) {
            if (empty($data['collaboration'])) {
                $data['collaboration'] = null;
                $data['collaboration_price'] = null;
            } else {
                if (!$req->has('collaboration_price') && $offering->collaboration_price === null) {
                    Log::channel('offerings')->warning('OFFERINGS_UPDATE_BAD_COMBO', $ctx);
                    return response()->json(['error' => 'collaboration_price wajib jika collaboration diisi'], 422);
                }
            }
        }

        // === Normalisasi add_ons (jika ada)
        if ($req->has('add_ons')) {
            $data['add_ons'] = $this->normalizeArray($data['add_ons'] ?? [], 50);
        }

        // ==== Pengolahan gambar ====
        $this->ensurePublicDir('offering');

        $currentPictures = is_array($offering->offer_pictures) ? $offering->offer_pictures : [];
        $finalPictures   = $currentPictures;

        // 4a) Jika mengirim offer_pictures (array string), REPLACE daftar lama
        if ($req->has('offer_pictures')) {
            $finalPictures = $this->normalizeArray($data['offer_pictures'] ?? [], 50);
        }

        // 4b) Upload file baru (APPEND) — tangkap semua variasi field
        $uploadedPaths = [];
        $files = [];

        // single
        if ($req->hasFile('offer_image')) {
            $f = $req->file('offer_image');
            if ($f) $files[] = $f;
        }
        // multiple (bisa nested)
        if ($req->hasFile('offer_images')) {
            $fi = $req->file('offer_images');           // UploadedFile|array
            $fi = is_array($fi) ? Arr::flatten($fi) : [$fi];
            foreach ($fi as $f) { if ($f) $files[] = $f; }
        }
        // sweep semua key yang diawali 'offer_images' (menangkap offer_images[] dsb)
        foreach ($req->allFiles() as $key => $val) {
            if (str_starts_with($key, 'offer_images')) {
                $arr = is_array($val) ? Arr::flatten($val) : [$val];
                foreach ($arr as $f) {
                    if ($f && !in_array($f, $files, true)) $files[] = $f;
                }
            }
        }

        foreach ($files as $file) {
            if (!$file->isValid()) continue;
            $path = $file->store('offering', 'public'); // storage/app/public/offering/xxx
            if ($path) $uploadedPaths[] = '/storage/' . $path;
        }

        if (!empty($uploadedPaths)) {
            $finalPictures = array_values(array_unique(array_merge($finalPictures, $uploadedPaths)));
            $finalPictures = array_slice($finalPictures, 0, 50);
        }

        // Hanya set jika ada perubahan via offer_pictures ATAU ada file baru
        if ($req->has('offer_pictures') || !empty($uploadedPaths)) {
            $data['offer_pictures'] = $finalPictures;
        }

        // === Simpan
        $offering->fill($data)->save();

        Log::channel('offerings')->info('OFFERINGS_UPDATE_SUCCESS', $ctx + [
            'added_files'  => count($uploadedPaths),
            'final_pics'   => count($finalPictures ?? []),
            'final_sample' => array_slice($finalPictures ?? [], 0, 3),
            'ms'           => (int) ((microtime(true) - $t0)*1000),
        ]);

        return response()->json($offering->fresh());
    } catch (Throwable $e) {
        Log::channel('offerings')->error('OFFERINGS_UPDATE_ERROR', $ctx + [
            'error' => $e->getMessage(),
            'ms'    => (int) ((microtime(true) - $t0)*1000),
        ]);
        throw $e;
    }
}


    /**
     * Membuat folder di disk 'public' jika belum ada.
     */
    protected function ensurePublicDir(string $dir): void
    {
        try {
            $disk = Storage::disk('public');
            if (!$disk->exists($dir)) {
                $disk->makeDirectory($dir);
            }
            $sentinel = rtrim($dir, '/'). '/.init';
            if (!$disk->exists($sentinel)) {
                $disk->put($sentinel, now()->toIso8601String());
            }
            $index = rtrim($dir, '/'). '/index.html';
            if (!$disk->exists($index)) {
                $disk->put($index, "<!-- static dir -->");
            }
            Log::channel('offerings')->info('ENSURE_PUBLIC_DIR_OK', [
                'dir' => $dir,
            ]);
        } catch (Throwable $e) {
            Log::channel('offerings')->warning('ENSURE_PUBLIC_DIR_FAIL', [
                'dir'   => $dir,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * DELETE /offerings/{offering}
     */
    public function destroy(Request $req, Offering $offering)
    {
        $t0  = microtime(true);
        $ctx = $this->baseCtx($req) + ['offeringId' => $offering->id];
        Log::channel('offerings')->info('OFFERINGS_DESTROY_ATTEMPT', $ctx);

        try {
            $this->guardOwnerOrAdmin($req->user()->id, $offering->mua_id);
            $offering->delete();

            Log::channel('offerings')->info('OFFERINGS_DESTROY_SUCCESS', $ctx + [
                'ms' => (int) ((microtime(true) - $t0)*1000),
            ]);

            return response()->json(['ok'=>true]);
        } catch (Throwable $e) {
            Log::channel('offerings')->error('OFFERINGS_DESTROY_ERROR', $ctx + [
                'error' => $e->getMessage(),
                'ms'    => (int) ((microtime(true) - $t0)*1000),
            ]);
            throw $e;
        }
    }

    /**
     * PATCH /offerings/{offering}/pictures
     */
    public function pictures(Request $req, Offering $offering)
    {
        $t0  = microtime(true);
        $ctx = $this->baseCtx($req) + ['offeringId' => $offering->id];
        Log::channel('offerings')->info('OFFERINGS_PICTURES_ATTEMPT', $ctx);

        try {
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
            } else { // add
                $offering->offer_pictures = $this->mergeUnique($current, $pics, 50);
            }

            $offering->save();

            Log::channel('offerings')->info('OFFERINGS_PICTURES_SUCCESS', $ctx + [
                'mode'     => $mode,
                'delta_in' => count($pics),
                'final'    => count((array)$offering->offer_pictures),
                'ms'       => (int) ((microtime(true) - $t0)*1000),
            ]);

            return response()->json($offering->fresh());
        } catch (Throwable $e) {
            Log::channel('offerings')->error('OFFERINGS_PICTURES_ERROR', $ctx + [
                'error' => $e->getMessage(),
                'ms'    => (int) ((microtime(true) - $t0)*1000),
            ]);
            throw $e;
        }
    }

    /**
     * PATCH /offerings/{offering}/addons
     */
    public function addons(Request $req, Offering $offering)
    {
        $t0  = microtime(true);
        $ctx = $this->baseCtx($req) + ['offeringId' => $offering->id];
        Log::channel('offerings')->info('OFFERINGS_ADDONS_ATTEMPT', $ctx);

        try {
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

            Log::channel('offerings')->info('OFFERINGS_ADDONS_SUCCESS', $ctx + [
                'mode'     => $mode,
                'delta_in' => count($add),
                'final'    => count((array)$offering->add_ons),
                'ms'       => (int) ((microtime(true) - $t0)*1000),
            ]);

            return response()->json($offering->fresh());
        } catch (Throwable $e) {
            Log::channel('offerings')->error('OFFERINGS_ADDONS_ERROR', $ctx + [
                'error' => $e->getMessage(),
                'ms'    => (int) ((microtime(true) - $t0)*1000),
            ]);
            throw $e;
        }
    }

    /**
     * POST /offerings/bulk
     */
    public function bulkStore(Request $req)
    {
        $t0  = microtime(true);
        $ctx = $this->baseCtx($req);
        Log::channel('offerings')->info('OFFERINGS_BULK_STORE_ATTEMPT', $ctx);

        try {
            $data = $req->validate([
                'items' => ['required','array','min:1','max:100'],
                'items.*.mua_id'              => ['required','uuid'],
                'items.*.name_offer'          => ['required','string','max:255'],
                'items.*.offer_pictures'      => ['nullable','array'],
                'items.*.offer_pictures.*'    => ['string','max:1000'],
                'items.*.makeup_type'         => ['nullable','string','max:100'],
                'items.*.collaboration'       => ['nullable','string','max:255'],
                'items.*.collaboration_price' => ['nullable','numeric','min:0'],
                'items.*.add_ons'             => ['nullable','array'],
                'items.*.add_ons.*'           => ['string','max:100'],
                'items.*.price'               => ['required','numeric','min:0'],
            ]);

            $actor = Profile::findOrFail($req->user()->id);

            $created = [];
            DB::transaction(function () use (&$created, $data, $actor, $ctx) {
                foreach ($data['items'] as $i => $it) {
                    if ($actor->role !== 'admin' && $it['mua_id'] !== $actor->id) {
                        Log::channel('offerings')->warning('OFFERINGS_BULK_STORE_FORBIDDEN_ITEM', $ctx + ['index'=>$i]);
                        abort(403, 'Forbidden');
                    }
                    if (empty($it['collaboration'])) {
                        $it['collaboration'] = null;
                        $it['collaboration_price'] = null;
                    } else {
                        if (!isset($it['collaboration_price'])) {
                            Log::channel('offerings')->warning('OFFERINGS_BULK_STORE_BAD_COMBO_ITEM', $ctx + ['index'=>$i]);
                            abort(422, 'collaboration_price wajib jika collaboration diisi');
                        }
                    }
                    $it['offer_pictures'] = $this->normalizeArray($it['offer_pictures'] ?? [], 50);
                    $it['add_ons']        = $this->normalizeArray($it['add_ons'] ?? [], 50);

                    $created[] = Offering::create($it);
                }
            });

            Log::channel('offerings')->info('OFFERINGS_BULK_STORE_SUCCESS', $ctx + [
                'created' => count($created),
                'ids'     => $this->briefArray(array_map(fn($m) => $m->id, $created), 10),
                'ms'      => (int) ((microtime(true) - $t0)*1000),
            ]);

            return response()->json($created, 201);
        } catch (Throwable $e) {
            Log::channel('offerings')->error('OFFERINGS_BULK_STORE_ERROR', $ctx + [
                'error' => $e->getMessage(),
                'ms'    => (int) ((microtime(true) - $t0)*1000),
            ]);
            throw $e;
        }
    }

    /* ====================== Helpers ====================== */

    private function guardOwnerOrAdmin(string $actorId, string $ownerId): void
    {
        $ok = false;
        try {
            $actor = Profile::findOrFail($actorId);
            $ok = ($actor->role === 'admin' || $actorId === $ownerId);
            if (!$ok) {
                Log::channel('offerings')->warning('GUARD_FORBIDDEN', [
                    'actorId' => $actorId,
                    'ownerId' => $ownerId,
                    'role'    => $actor->role,
                ]);
                abort(403, 'Forbidden');
            }
        } catch (Throwable $e) {
            Log::channel('offerings')->error('GUARD_ERROR', [
                'actorId' => $actorId,
                'ownerId' => $ownerId,
                'error'   => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /** Normalisasi array string (trim, unique, limit) */
    private function normalizeArray(array $arr, int $limit = 50): array
    {
        $out = array_values(array_filter(array_map(fn($s) => trim((string)$s), $arr), fn($s) => $s !== ''));
        $out = array_values(array_unique($out));
        if (count($out) > $limit) $out = array_slice($out, 0, $limit);
        return $out;
    }

    /** Merge dua array string lalu unique+limit */
    private function mergeUnique(array $a, array $b, int $limit = 50): array
    {
        $m = array_values(array_unique(array_merge($a, $b)));
        if (count($m) > $limit) $m = array_slice($m, 0, $limit);
        return $m;
    }


    public function deletePictures(Request $req, Offering $offering)
{
    $t0  = microtime(true);
    $ctx = $this->baseCtx($req) + ['offeringId' => $offering->id];
    Log::channel('offerings')->info('OFFERINGS_PICTURES_DELETE_ATTEMPT', $ctx);

    try {
        if (!$req->user()) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        $this->guardOwnerOrAdmin($req->user()->id, $offering->mua_id);

        // Validasi input fleksibel: by URLs atau by index
        $data = $req->validate([
            'pictures'          => ['sometimes','array','min:1'],
            'pictures.*'        => ['string','max:1000'],
            'index'             => ['sometimes','integer','min:0'],
            'also_delete_files' => ['sometimes','boolean'],
        ]);

        $current = is_array($offering->offer_pictures) ? $offering->offer_pictures : [];
        $alsoDel = (bool)($data['also_delete_files'] ?? false);

        // Tentukan mana yang akan dihapus
        $toRemove = [];

        if (array_key_exists('index', $data)) {
            $idx = $data['index'];
            if (!array_key_exists($idx, $current)) {
                return response()->json(['error' => 'Index tidak valid'], 422);
            }
            $toRemove[] = $current[$idx];
        }

        if (!empty($data['pictures'])) {
            // normalisasi & intersect supaya aman
            $want = $this->normalizeArray($data['pictures'], 100);
            $toRemove = array_values(array_unique(array_merge($toRemove, $want)));
            $toRemove = array_values(array_intersect($toRemove, $current));
            if (empty($toRemove)) {
                return response()->json(['error' => 'Tidak ada gambar yang cocok untuk dihapus'], 422);
            }
        }

        if (empty($toRemove)) {
            return response()->json(['error' => 'Harus kirim "pictures" atau "index"'], 422);
        }

        // Hapus dari array
        $updated = array_values(array_diff($current, $toRemove));

        // (Opsional) Hapus file fisik di disk 'public'
        $deletedFiles = [];
        if ($alsoDel) {
            $disk = Storage::disk('public');
            foreach ($toRemove as $url) {
                // Terima path seperti "/storage/offering/xxx.jpg"
                if (str_starts_with($url, '/storage/')) {
                    $rel = substr($url, strlen('/storage/')); // offering/xxx.jpg
                } else {
                    // Kalau FE kirim "offering/xxx.jpg" juga kita handle
                    $rel = ltrim($url, '/');
                    if (!str_starts_with($rel, 'offering/')) continue; // batasi hanya folder offering
                }
                if ($disk->exists($rel)) {
                    if ($disk->delete($rel)) $deletedFiles[] = $rel;
                }
            }
        }

        // Simpan perubahan
        $offering->offer_pictures = $updated;
        $offering->save();

        Log::channel('offerings')->info('OFFERINGS_PICTURES_DELETE_SUCCESS', $ctx + [
            'removed_count' => count($toRemove),
            'deleted_files' => $deletedFiles,
            'final'         => count($updated),
            'ms'            => (int)((microtime(true) - $t0) * 1000),
        ]);

        return response()->json($offering->fresh());
    } catch (Throwable $e) {
        Log::channel('offerings')->error('OFFERINGS_PICTURES_DELETE_ERROR', $ctx + [
            'error' => $e->getMessage(),
            'ms'    => (int)((microtime(true) - $t0) * 1000),
        ]);
        throw $e;
    }
}
}
