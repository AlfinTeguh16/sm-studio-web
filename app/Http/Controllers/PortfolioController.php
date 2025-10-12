<?php

namespace App\Http\Controllers;

use App\Models\{Portfolio, Profile};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\File as FileRule;
use Illuminate\Support\Facades\Log;

class PortfolioController extends Controller
{
    /* ==================== LIST & SHOW ==================== */

    public function index(Request $req)
    {
        $rid = $this->rid($req);
        Log::info('PORTFOLIO.index: start', $this->ctx($req, $rid));

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

        Log::info('PORTFOLIO.index: done', ['rid'=>$rid, 'count'=>$data->count(), 'page'=>$data->currentPage()]);
        return response()->json($data);
    }

    public function show(Request $req, Portfolio $portfolio)
    {
        $rid = $this->rid($req);
        Log::info('PORTFOLIO.show', ['rid'=>$rid, 'id'=>$portfolio->id]);
        return response()->json($portfolio);
    }

    public function mine(Request $req)
    {
        $rid = $this->rid($req);
        Log::info('PORTFOLIO.mine: start', ['rid'=>$rid, 'user'=>$req->user()->id ?? null]);

        $authId = $req->user()->id;
        $data = Portfolio::where('mua_id', $authId)
            ->orderByDesc('created_at')
            ->paginate((int)max(1, min(100, $req->query('per_page', 20))));

        Log::info('PORTFOLIO.mine: done', ['rid'=>$rid, 'count'=>$data->count()]);
        return $data;
    }

    /* ==================== CREATE ==================== */

    public function store(Request $req)
    {
        $rid = $this->rid($req);
        Log::info('PORTFOLIO.store: start', $this->ctx($req, $rid, ['fields'=>$req->only(['mua_id','name','makeup_type','collaboration'])]));

        try {
            $base = $req->validate([
                'mua_id'        => ['required','uuid'],
                'name'          => ['required','string','max:255'],
                'makeup_type'   => ['nullable','string','max:100'],
                'collaboration' => ['nullable','string','max:255'],
            ]);

            $this->guardOwnerOrAdmin($req->user()->id, $base['mua_id']);

            $this->validatePhotosArray($req);
            $finalUrls = $this->collectPhotoUrlsFromRequest($req, null, $rid);

            $payload = array_merge($base, [
                'photos' => $this->normalizeArray($finalUrls, 100),
            ]);

            $p = Portfolio::create($payload);

            Log::info('PORTFOLIO.store: success', ['rid'=>$rid, 'id'=>$p->id, 'photos_count'=>count($payload['photos'] ?? [])]);
            return response()->json($p, 201);
        } catch (\Throwable $e) {
            Log::error('PORTFOLIO.store: error', ['rid'=>$rid, 'err'=>$e->getMessage(), 'trace'=>Str::limit($e->getTraceAsString(), 1500)]);
            throw $e;
        }
    }

    /* ==================== UPDATE ==================== */

    public function update(Request $req, Portfolio $portfolio)
    {
        $rid = $this->rid($req);
        Log::info('PORTFOLIO.update: start', $this->ctx($req, $rid, ['id'=>$portfolio->id]));

        try {
            $this->guardOwnerOrAdmin($req->user()->id, $portfolio->mua_id);

            $data = $req->validate([
                'name'          => ['sometimes','string','max:255'],
                'makeup_type'   => ['sometimes','nullable','string','max:100'],
                'collaboration' => ['sometimes','nullable','string','max:255'],
            ]);

            if ($req->has('photos') || $req->hasFile('photos')) {
                $this->validatePhotosArray($req);
                $newUrls = $this->collectPhotoUrlsFromRequest($req, null, $rid);
                $newUrls = $this->normalizeArray($newUrls, 100);

                $old = is_array($portfolio->photos) ? $portfolio->photos : [];
                $toDelete = array_diff($old, $newUrls);
                $this->deleteOwnedPhotos($toDelete, $rid);

                $data['photos'] = $newUrls;
                Log::info('PORTFOLIO.update: photos replaced', ['rid'=>$rid, 'old_count'=>count($old), 'new_count'=>count($newUrls)]);
            }

            $portfolio->fill($data)->save();
            Log::info('PORTFOLIO.update: success', ['rid'=>$rid, 'id'=>$portfolio->id]);
            return response()->json($portfolio->fresh());
        } catch (\Throwable $e) {
            Log::error('PORTFOLIO.update: error', ['rid'=>$rid, 'id'=>$portfolio->id, 'err'=>$e->getMessage()]);
            throw $e;
        }
    }

    /* ==================== DELETE ==================== */

    public function destroy(Request $req, Portfolio $portfolio)
    {
        $rid = $this->rid($req);
        Log::warning('PORTFOLIO.destroy: start', ['rid'=>$rid, 'id'=>$portfolio->id]);

        $this->guardOwnerOrAdmin($req->user()->id, $portfolio->mua_id);

        $this->deleteOwnedPhotos($portfolio->photos ?? [], $rid);
        $portfolio->delete();

        Log::warning('PORTFOLIO.destroy: deleted', ['rid'=>$rid, 'id'=>$portfolio->id]);
        return response()->json(['ok'=>true]);
    }

    /* ==================== PICTURES ==================== */

    public function pictures(Request $req, Portfolio $portfolio)
    {
        $rid = $this->rid($req);
        Log::info('PORTFOLIO.pictures: start', $this->ctx($req, $rid, ['id'=>$portfolio->id, 'mode'=>$req->input('mode')]));

        try {
            $this->guardOwnerOrAdmin($req->user()->id, $portfolio->mua_id);

            $req->validate([
                'mode' => ['nullable', Rule::in(['add','remove','replace'])],
            ]);
            $mode = $req->input('mode', 'add');

            $this->validatePhotosArray($req);
            $incoming = $this->collectPhotoUrlsFromRequest($req, null, $rid);
            $incoming = $this->normalizeArray($incoming, 100);

            $current = is_array($portfolio->photos) ? $portfolio->photos : [];

            if ($mode === 'replace') {
                $this->deleteOwnedPhotos(array_diff($current, $incoming), $rid);
                $portfolio->photos = $incoming;
            } elseif ($mode === 'remove') {
                $toRemove = array_values(array_intersect($current, $incoming));
                $this->deleteOwnedPhotos($toRemove, $rid);
                $portfolio->photos = array_values(array_diff($current, $incoming));
            } else {
                $portfolio->photos = $this->mergeUnique($current, $incoming, 100);
            }

            $portfolio->save();
            Log::info('PORTFOLIO.pictures: success', ['rid'=>$rid, 'id'=>$portfolio->id, 'mode'=>$mode, 'count'=>count($portfolio->photos ?? [])]);
            return response()->json($portfolio->fresh());
        } catch (\Throwable $e) {
            Log::error('PORTFOLIO.pictures: error', ['rid'=>$rid, 'id'=>$portfolio->id, 'err'=>$e->getMessage()]);
            throw $e;
        }
    }

    /* ==================== BULK ==================== */

    public function bulkStore(Request $req)
    {
        $rid = $this->rid($req);
        Log::info('PORTFOLIO.bulkStore: start', $this->ctx($req, $rid));

        $data = $req->validate([
            'items'                 => ['required','array','min:1','max:100'],
            'items.*.mua_id'        => ['required','uuid'],
            'items.*.name'          => ['required','string','max:255'],
            'items.*.makeup_type'   => ['nullable','string','max:100'],
            'items.*.collaboration' => ['nullable','string','max:255'],
        ]);

        $actor = Profile::findOrFail($req->user()->id);

        $created = [];
        DB::transaction(function () use (&$created, $data, $actor, $req, $rid) {
            foreach ($data['items'] as $idx => $it) {
                if ($actor->role !== 'admin' && $it['mua_id'] !== $actor->id) {
                    Log::warning('PORTFOLIO.bulkStore: forbidden item', ['rid'=>$rid, 'item_index'=>$idx, 'actor'=>$actor->id, 'owner'=>$it['mua_id']]);
                    abort(403, 'Forbidden');
                }

                $this->validatePhotosArray($req, "items.$idx.photos");
                $finalUrls = $this->collectPhotoUrlsFromRequest($req, "items.$idx.photos", $rid);

                $payload = [
                    'mua_id'        => $it['mua_id'],
                    'name'          => $it['name'],
                    'makeup_type'   => $it['makeup_type'] ?? null,
                    'collaboration' => $it['collaboration'] ?? null,
                    'photos'        => $this->normalizeArray($finalUrls, 100),
                ];

                $row = Portfolio::create($payload);
                $created[] = $row;

                Log::info('PORTFOLIO.bulkStore: item created', ['rid'=>$rid, 'item_index'=>$idx, 'id'=>$row->id, 'photos_count'=>count($payload['photos'])]);
            }
        });

        Log::info('PORTFOLIO.bulkStore: success', ['rid'=>$rid, 'created_count'=>count($created)]);
        return response()->json($created, 201);
    }

    /* ==================== Helpers ==================== */

    private function guardOwnerOrAdmin(string $actorId, string $ownerId): void
    {
        $actor = Profile::findOrFail($actorId);
        if ($actor->role !== 'admin' && $actorId !== $ownerId) {
            Log::warning('PORTFOLIO.guard: forbidden', ['actor'=>$actorId, 'owner'=>$ownerId]);
            abort(403, 'Forbidden');
        }
    }

    private function validatePhotosArray(Request $req, ?string $fieldPath = null): void
    {
        $root = $fieldPath ?: 'photos';

        $rules = [
            $root     => ['nullable','array'],
            "$root.*" => ['nullable'],
        ];

        Validator::make($req->all(), $rules)->after(function ($validator) use ($req, $root) {
            $items = $req->input($root, []);
            $files = $req->file($root);

            if (is_array($items)) {
                foreach ($items as $i => $val) {
                    if (is_string($val)) {
                        $url = trim($val);
                        if ($url === '') {
                            $validator->errors()->add("$root.$i", 'URL tidak boleh kosong.');
                        } elseif (!preg_match('~^https?://|^/storage/~i', $url)) {
                            $validator->errors()->add("$root.$i", 'URL tidak valid.');
                        }
                    }
                }
            }

            $rule = FileRule::image()->types(['jpg','jpeg','png','webp','heic','heif'])->max(4 * 1024);
            if (is_array($files)) {
                foreach ($files as $i => $f) {
                    if ($f === null) continue;
                    $res = Validator::make([$root => $f], [$root => [$rule]]);
                    if ($res->fails()) {
                        foreach ($res->errors()->all() as $msg) {
                            $validator->errors()->add("$root.$i", $msg);
                        }
                    }
                }
            } elseif ($files) {
                $res = Validator::make([$root => $files], [$root => [$rule]]);
                if ($res->fails()) {
                    foreach ($res->errors()->all() as $msg) {
                        $validator->errors()->add($root, $msg);
                    }
                }
            }
        })->validate();
    }

    private function collectPhotoUrlsFromRequest(Request $req, ?string $fieldPath = null, ?string $rid = null): array
    {
        $root = $fieldPath ?: 'photos';
        $rid = $rid ?? $this->rid($req);

        $urls = [];

        // 1) dari input string
        $inp = $req->input($root, []);
        if (is_array($inp)) {
            foreach ($inp as $v) {
                if (is_string($v) && ($v = trim($v)) !== '') $urls[] = $v;
            }
        } elseif (is_string($inp) && ($inp = trim($inp)) !== '') {
            $urls[] = $inp;
        }

        // 2) dari file
        $files = $req->file($root);
        $count = is_array($files) ? count($files) : ($files ? 1 : 0);
        Log::info('PORTFOLIO.collectPhotos: incoming files', ['rid'=>$rid, 'field'=>$root, 'count'=>$count]);

        if (is_array($files)) {
            foreach ($files as $idx => $f) {
                if ($f && $f->isValid()) {
                    $path = $f->store('portfolio_photos', 'public');
                    $url  = Storage::url($path);
                    $urls[] = $url;
                    Log::info('PORTFOLIO.collectPhotos: stored', [
                        'rid'=>$rid, 'field'=>$root, 'idx'=>$idx,
                        'mime'=>$f->getMimeType(), 'size'=>$f->getSize(), 'path'=>$path, 'url'=>$url
                    ]);
                } else {
                    Log::warning('PORTFOLIO.collectPhotos: invalid file', ['rid'=>$rid, 'field'=>$root, 'idx'=>$idx]);
                }
            }
        } elseif ($files && $files->isValid()) {
            $path = $files->store('portfolio_photos', 'public');
            $url  = Storage::url($path);
            $urls[] = $url;
            Log::info('PORTFOLIO.collectPhotos: stored(single)', [
                'rid'=>$rid, 'field'=>$root, 'mime'=>$files->getMimeType(), 'size'=>$files->getSize(), 'path'=>$path, 'url'=>$url
            ]);
        }

        Log::info('PORTFOLIO.collectPhotos: done', ['rid'=>$rid, 'field'=>$root, 'final_count'=>count($urls)]);
        return $urls;
    }

    private function deleteOwnedPhotos(iterable $photoUrls, ?string $rid = null): void
    {
        $rid = $rid ?? Str::uuid()->toString();
        foreach ($photoUrls as $url) {
            if (!is_string($url)) continue;
            $pos = strpos($url, '/storage/');
            if ($pos === false) continue;

            $rel = substr($url, $pos + strlen('/storage/'));
            if (Str::startsWith($rel, 'portfolio_photos/')) {
                if (Storage::disk('public')->exists($rel)) {
                    Storage::disk('public')->delete($rel);
                    Log::info('PORTFOLIO.deleteOwned: deleted', ['rid'=>$rid, 'rel'=>$rel]);
                } else {
                    Log::info('PORTFOLIO.deleteOwned: not_found', ['rid'=>$rid, 'rel'=>$rel]);
                }
            }
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

    /* ==================== Logging utils ==================== */

    private function rid(Request $req): string
    {
        return (string) ($req->header('X-Req-Id') ?: Str::uuid());
    }

    private function ctx(Request $req, string $rid, array $extra = []): array
    {
        return array_merge([
            'rid' => $rid,
            'user' => optional($req->user())->id,
            'ip' => $req->ip(),
            'ua' => substr((string)$req->userAgent(), 0, 200),
            'ct' => $req->header('Content-Type'),
            'has_files' => $req->hasFile('photos') ? true : false,
        ], $extra);
    }
}
