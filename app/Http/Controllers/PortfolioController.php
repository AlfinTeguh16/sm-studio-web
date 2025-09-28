<?php

namespace App\Http\Controllers;

use App\Models\{Portfolio, Profile};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PortfolioController extends Controller
{
    /**
     * GET /portfolios
     * Query (opsional):
     * - muaId=uuid
     * - makeup_type=string
     * - created_from, created_to (Y-m-d)
     * - q=keyword (cari di name/collaboration)
     * - per_page=1..100 (default 20), page
     * - sort=created_at (default), dir=asc|desc (default: desc)
     */
    public function index(Request $req)
    {
        $per = (int) $req->query('per_page', 20);
        $per = max(1, min(100, $per));

        $sort = $req->query('sort', 'created_at');
        $dir  = strtolower($req->query('dir', 'desc'));
        if ($sort !== 'created_at') $sort = 'created_at';
        if (!in_array($dir, ['asc','desc'], true)) $dir = 'desc';

        $q = Portfolio::query();

        if ($req->filled('muaId'))       $q->where('mua_id', $req->query('muaId'));
        if ($req->filled('makeup_type')) $q->where('makeup_type', $req->query('makeup_type'));

        if ($req->filled('created_from')) $q->whereDate('created_at', '>=', $req->query('created_from'));
        if ($req->filled('created_to'))   $q->whereDate('created_at', '<=', $req->query('created_to'));

        if ($req->filled('q')) {
            $kw = '%'.$req->query('q').'%';
            $q->where(function ($qq) use ($kw) {
                $qq->where('name','like',$kw)->orWhere('collaboration','like',$kw);
            });
        }

        $data = $q->orderBy($sort, $dir)->paginate($per);
        return response()->json($data);
    }

    /**
     * GET /portfolios/{portfolio}
     */
    public function show(Request $req, Portfolio $portfolio)
    {
        return response()->json($portfolio);
    }

    /**
     * GET /portfolios/mine
     * Portofolio milik user login (MUA).
     */
    public function mine(Request $req)
    {
        $authId = $req->user()->id;
        return Portfolio::where('mua_id', $authId)
            ->orderByDesc('created_at')
            ->paginate((int)max(1, min(100, $req->query('per_page', 20))));
    }

    /**
     * POST /portfolios
     * Body:
     *  - mua_id (uuid)
     *  - name (string)
     *  - photos? (string[])      // array URL
     *  - makeup_type? (string)
     *  - collaboration? (string|null)
     */
    public function store(Request $req)
    {
        $data = $req->validate([
            'mua_id'       => ['required','uuid'],
            'name'         => ['required','string','max:255'],
            'photos'       => ['nullable','array'],
            'photos.*'     => ['string','max:1000'],
            'makeup_type'  => ['nullable','string','max:100'],
            'collaboration'=> ['nullable','string','max:255'],
        ]);

        $this->guardOwnerOrAdmin($req->user()->id, $data['mua_id']);

        $data['photos'] = $this->normalizeArray($data['photos'] ?? [], 100);
        $p = Portfolio::create($data);

        return response()->json($p, 201);
    }

    /**
     * PUT/PATCH /portfolios/{portfolio}
     * Partial update; jika "photos" dikirim → REPLACE seluruh array.
     * Untuk tambah/hapus sebagian gunakan endpoint /pictures.
     */
    public function update(Request $req, Portfolio $portfolio)
    {
        $this->guardOwnerOrAdmin($req->user()->id, $portfolio->mua_id);

        $data = $req->validate([
            'name'          => ['sometimes','string','max:255'],
            'photos'        => ['sometimes','array'],
            'photos.*'      => ['string','max:1000'],
            'makeup_type'   => ['sometimes','nullable','string','max:100'],
            'collaboration' => ['sometimes','nullable','string','max:255'],
        ]);

        if (array_key_exists('photos', $data)) {
            $data['photos'] = $this->normalizeArray($data['photos'] ?? [], 100);
        }

        $portfolio->fill($data)->save();
        return response()->json($portfolio->fresh());
    }

    /**
     * DELETE /portfolios/{portfolio}
     */
    public function destroy(Request $req, Portfolio $portfolio)
    {
        $this->guardOwnerOrAdmin($req->user()->id, $portfolio->mua_id);
        $portfolio->delete();
        return response()->json(['ok'=>true]);
    }

    /**
     * PATCH /portfolios/{portfolio}/pictures
     * Body:
     *  - mode=add|remove|replace (default: add)
     *  - photos: string[]
     */
    public function pictures(Request $req, Portfolio $portfolio)
    {
        $this->guardOwnerOrAdmin($req->user()->id, $portfolio->mua_id);

        $data = $req->validate([
            'mode'        => ['nullable', Rule::in(['add','remove','replace'])],
            'photos'      => ['required','array','min:1'],
            'photos.*'    => ['string','max:1000'],
        ]);

        $mode = $data['mode'] ?? 'add';
        $pics = $this->normalizeArray($data['photos'], 100);

        $current = $portfolio->photos ?? [];

        if ($mode === 'replace') {
            $portfolio->photos = $pics;
        } elseif ($mode === 'remove') {
            $portfolio->photos = array_values(array_diff($current, $pics));
        } else { // add/merge
            $portfolio->photos = $this->mergeUnique($current, $pics, 100);
        }

        $portfolio->save();
        return response()->json($portfolio->fresh());
    }

    /**
     * POST /portfolios/bulk
     * Body: { items: [ { ...payload seperti store()... }, ... ] } (max 100)
     * Non-admin hanya boleh menambahkan untuk dirinya sendiri.
     */
    public function bulkStore(Request $req)
    {
        $data = $req->validate([
            'items' => ['required','array','min:1','max:100'],
            'items.*.mua_id'       => ['required','uuid'],
            'items.*.name'         => ['required','string','max:255'],
            'items.*.photos'       => ['nullable','array'],
            'items.*.photos.*'     => ['string','max:1000'],
            'items.*.makeup_type'  => ['nullable','string','max:100'],
            'items.*.collaboration'=> ['nullable','string','max:255'],
        ]);

        $actor = Profile::findOrFail($req->user()->id);

        $created = [];
        DB::transaction(function () use (&$created, $data, $actor) {
            foreach ($data['items'] as $it) {
                if ($actor->role !== 'admin' && $it['mua_id'] !== $actor->id) {
                    abort(403, 'Forbidden');
                }
                $it['photos'] = $this->normalizeArray($it['photos'] ?? [], 100);
                $created[] = Portfolio::create($it);
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

    private function normalizeArray(array $arr, int $limit = 100): array
    {
        $arr = array_values(array_filter(array_map(fn($s) => trim((string)$s), $arr), fn($s) => $s !== ''));
        $arr = array_values(array_unique($arr));
        if (count($arr) > $limit) $arr = array_slice($arr, 0, $limit);
        return $arr;
    }

    private function mergeUnique(array $a, array $b, int $limit = 100): array
    {
        $m = array_values(array_unique(array_merge($a, $b)));
        if (count($m) > $limit) $m = array_slice($m, 0, $limit);
        return $m;
    }
}
