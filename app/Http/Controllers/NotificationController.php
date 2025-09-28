<?php

namespace App\Http\Controllers;

use App\Models\{Notification, Profile};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    private const TYPES = ['booking','system','payment'];

    /**
     * GET /notifications
     * Query:
     * - userId=uuid        (admin saja; selain admin diabaikan → pakai auth user)
     * - type=booking|system|payment (boleh comma separated)
     * - is_read=true|false
     * - created_from=YYYY-MM-DD, created_to=YYYY-MM-DD
     * - q=keyword (cari di title/message)
     * - per_page=1..100 (default 20), page
     */
    public function index(Request $req)
    {
        $authId = $req->user()->id;
        $actor  = Profile::findOrFail($authId);

        $per = (int) $req->query('per_page', 20);
        $per = max(1, min(100, $per));

        $q = Notification::query();

        // Scope user
        $userId = $authId;
        if ($actor->role === 'admin' && $req->filled('userId')) {
            $userId = $req->query('userId');
        }
        $q->where('user_id', $userId);

        // Filter type (comma separated)
        if ($req->filled('type')) {
            $types = array_filter(explode(',', $req->query('type')));
            $types = array_values(array_intersect($types, self::TYPES));
            if ($types) $q->whereIn('type', $types);
        }

        // Filter read/unread
        if ($req->has('is_read')) {
            $isRead = filter_var($req->query('is_read'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isRead !== null) $q->where('is_read', $isRead);
        }

        // Date range
        if ($req->filled('created_from')) $q->whereDate('created_at', '>=', $req->query('created_from'));
        if ($req->filled('created_to'))   $q->whereDate('created_at', '<=', $req->query('created_to'));

        // Search
        if ($req->filled('q')) {
            $kw = '%'.$req->query('q').'%';
            $q->where(function ($qq) use ($kw) {
                $qq->where('title','like',$kw)->orWhere('message','like',$kw);
            });
        }

        $data = $q->orderByDesc('created_at')->paginate($per);
        return response()->json($data);
    }

    /**
     * GET /notifications/{id}
     * Detail—hanya pemilik atau admin.
     */
    public function show(Request $req, Notification $notification)
    {
        $authId = $req->user()->id;
        $role   = Profile::findOrFail($authId)->role;

        if ($notification->user_id !== $authId && $role !== 'admin') {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        return response()->json($notification);
    }

    /**
     * GET /notifications/unread-count
     * Query: userId? (admin saja)
     */
    public function unreadCount(Request $req)
    {
        $authId = $req->user()->id;
        $actor  = Profile::findOrFail($authId);

        $userId = $authId;
        if ($actor->role === 'admin' && $req->filled('userId')) {
            $userId = $req->query('userId');
        }

        $count = Notification::where('user_id', $userId)->where('is_read', false)->count();
        return response()->json(['count' => $count]);
    }

    /**
     * PATCH /notifications/{id}/read
     * Body: is_read? (default: true)
     */
    public function markRead(Request $req, $id)
    {
        $authId = $req->user()->id;
        $actor  = Profile::findOrFail($authId);

        $notif = Notification::findOrFail($id);
        if ($notif->user_id !== $authId && $actor->role !== 'admin') {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $isRead = $req->has('is_read')
            ? (bool) $req->boolean('is_read')
            : true;

        $notif->is_read = $isRead;
        $notif->save();

        return response()->json($notif);
    }

    /**
     * PATCH /notifications/read-all
     * Body: user_id? (admin saja); type? (filter opsional)
     */
    public function markAllRead(Request $req)
    {
        $authId = $req->user()->id;
        $actor  = Profile::findOrFail($authId);

        $userId = $actor->role === 'admin' && $req->filled('user_id')
            ? $req->input('user_id')
            : $authId;

        $q = Notification::where('user_id', $userId)->where('is_read', false);

        if ($req->filled('type') && in_array($req->input('type'), self::TYPES, true)) {
            $q->where('type', $req->input('type'));
        }

        $updated = $q->update(['is_read' => true]);

        return response()->json(['updated' => $updated]);
    }

    /**
     * POST /notifications
     * Buat notifikasi baru.
     * Body:
     * - user_id? (uuid) → jika tidak admin, wajib sama dengan auth user
     * - user_ids? (array uuid) → admin saja
     * - title (string), message (string), type=booking|system|payment (opsional; default: system)
     */
    public function store(Request $req)
    {
        $authId = $req->user()->id;
        $actor  = Profile::findOrFail($authId);

        $data = $req->validate([
            'user_id'  => ['nullable','uuid'],
            'user_ids' => ['nullable','array','min:1'],
            'user_ids.*' => ['uuid'],
            'title'    => ['required','string','max:255'],
            'message'  => ['required','string','max:2000'],
            'type'     => ['nullable', Rule::in(self::TYPES)],
        ]);

        $type = $data['type'] ?? 'system';

        // Non-admin hanya boleh kirim ke dirinya sendiri
        if ($actor->role !== 'admin') {
            $targetId = $data['user_id'] ?? $authId;
            if ($targetId !== $authId || !empty($data['user_ids'])) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            $notif = Notification::create([
                'user_id' => $authId,
                'title'   => $data['title'],
                'message' => $data['message'],
                'type'    => $type,
            ]);
            return response()->json($notif, 201);
        }

        // Admin: single atau bulk
        $created = [];
        DB::transaction(function () use (&$created, $data, $type) {
            if (!empty($data['user_ids'])) {
                foreach ($data['user_ids'] as $uid) {
                    $created[] = Notification::create([
                        'user_id' => $uid,
                        'title'   => $data['title'],
                        'message' => $data['message'],
                        'type'    => $type,
                    ]);
                }
            } else {
                $uid = $data['user_id'] ?? null;
                if (!$uid) abort(422, 'user_id atau user_ids wajib untuk admin');
                $created[] = Notification::create([
                    'user_id' => $uid,
                    'title'   => $data['title'],
                    'message' => $data['message'],
                    'type'    => $type,
                ]);
            }
        });

        return response()->json(count($created) === 1 ? $created[0] : $created, 201);
    }

    /**
     * DELETE /notifications/{id}
     * Hanya pemilik atau admin.
     */
    public function destroy(Request $req, Notification $notification)
    {
        $authId = $req->user()->id;
        $role   = Profile::findOrFail($authId)->role;

        if ($notification->user_id !== $authId && $role !== 'admin') {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $notification->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /notifications
     * Hapus massal (hanya notifikasi milik user). Admin bisa target user lain.
     * Query/Body:
     * - user_id? (admin saja)
     * - only_read=true (default: true) → kalau false, akan hapus SEMUA milik user (hati-hati!)
     * - type? (opsional filter)
     */
    public function destroyAll(Request $req)
    {
        $authId = $req->user()->id;
        $actor  = Profile::findOrFail($authId);

        $userId = $actor->role === 'admin' && $req->filled('user_id') ? $req->input('user_id') : $authId;
        $onlyRead = $req->has('only_read') ? $req->boolean('only_read') : true;

        $q = Notification::where('user_id', $userId);
        if ($onlyRead) $q->where('is_read', true);
        if ($req->filled('type') && in_array($req->input('type'), self::TYPES, true)) {
            $q->where('type', $req->input('type'));
        }

        $deleted = $q->delete();
        return response()->json(['deleted' => $deleted]);
    }
}
