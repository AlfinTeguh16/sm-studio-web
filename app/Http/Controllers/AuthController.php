<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\File as FileRule;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Simpan file gambar profil ke disk 'public/profile_photos' dan kembalikan
     * array berisi path & url publik (via Storage::url()).
     * Menerima file dari salah satu field: photo_url atau photo.
     *
     * @return array{path:string,url:string}|null
     */
    protected function saveProfileImage(Request $request, array $fieldCandidates = ['photo_url','photo']): ?array
    {
        foreach ($fieldCandidates as $field) {
            if ($request->hasFile($field)) {
                $file = $request->file($field);
                if ($file && $file->isValid()) {
                    // Simpan ke storage/app/public/profile_photos/xxxx.ext
                    $path = $file->store('profile_photos', 'public');
                    return [
                        'path' => $path,               // profile_photos/xxx.jpg
                        'url'  => Storage::url($path), // /storage/profile_photos/xxx.jpg
                    ];
                }
            }
        }
        return null;
    }

    /**
     * Hapus file lama jika URL menunjuk ke storage publik kita di bawah /storage/profile_photos/.
     */
    protected function deleteOldPhotoIfOwned(?string $photoUrl): void
    {
        if (!$photoUrl) return;

        // URL contoh: /storage/profile_photos/xxx.jpg atau https://domain/storage/profile_photos/xxx.jpg
        $pos = strpos($photoUrl, '/storage/');
        if ($pos === false) return; // bukan file di storage publik

        $relative = substr($photoUrl, $pos + strlen('/storage/')); // profile_photos/xxx.jpg
        if (str_starts_with($relative, 'profile_photos/')) {
            if (Storage::disk('public')->exists($relative)) {
                Storage::disk('public')->delete($relative);
            }
        }
    }

    /**
     * PATCH/POST /auth/profile
     * (Mobile mengirim POST + _method=PATCH)
     * Body:
     *  - name, phone, bio, address, services (array/string CSV), location_lat, location_lng
     *  - photo_url (file) atau photo (file)
     *  - remove_photo? (boolean)
     */
    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name'          => ['nullable','string','max:100'],
            'phone'         => ['nullable','string','max:30'],
            'bio'           => ['nullable','string','max:1000'],
            'address'       => ['nullable','string','max:255'],
            'services'      => ['nullable'],  
            'services.*'    => ['sometimes','string','max:100'],
            'location_lat'  => ['nullable','numeric','between:-90,90'],
            'location_lng'  => ['nullable','numeric','between:-180,180'],

            // file bisa di salah satu nama
            'photo_url'     => ['nullable', FileRule::image()->types(['jpg','jpeg','png','webp','heic'])->max(2 * 1024)],
            'photo'         => ['nullable', FileRule::image()->types(['jpg','jpeg','png','webp','heic'])->max(2 * 1024)],

            'remove_photo'  => ['nullable','boolean'],
        ]);

        $user = $request->user();

        // Normalisasi services: string CSV -> array, array -> trimmed
        if (array_key_exists('services', $data) && $data['services'] !== null) {
            if (is_string($data['services'])) {
                $items = array_filter(array_map('trim', explode(',', $data['services'])));
                $data['services'] = array_values($items);
            } elseif (is_array($data['services'])) {
                $data['services'] = array_values(array_filter(
                    array_map(fn($s) => is_string($s) ? trim($s) : $s, $data['services']),
                    fn($s) => $s !== ''
                ));
            } else {
                $data['services'] = null;
            }
        }

        $result = DB::transaction(function () use ($data, $user, $request) {

            // Sinkron nama user jika diisi
            if (array_key_exists('name', $data) && $data['name'] !== null) {
                $user->name = $data['name'];
                $user->save();
            }

            // Ambil/buat profile (PK = users.id)
            $profile = Profile::firstOrNew(['id' => $user->id]);
            if (!$profile->exists && empty($profile->role)) {
                $profile->role = 'customer';
            }

            // Isi field sederhana
            foreach (['phone','bio','address','location_lat','location_lng'] as $f) {
                if (array_key_exists($f, $data)) {
                    $profile->{$f} = $data[$f];
                }
            }
            if (array_key_exists('services', $data)) {
                $profile->services = $data['services']; // diasumsikan cast json di model
            }

            // Hapus foto lama bila diminta
            $remove = (bool)($data['remove_photo'] ?? false);
            if ($remove && $profile->photo_url) {
                $this->deleteOldPhotoIfOwned($profile->photo_url);
                $profile->photo_url = null;
            }

            // Upload foto baru jika ada (terima photo_url atau photo)
            $saved = $this->saveProfileImage($request, ['photo_url','photo']);
            if ($saved) {
                // bersihkan foto lama kalau ada
                if (!empty($profile->photo_url)) {
                    $this->deleteOldPhotoIfOwned($profile->photo_url);
                }
                $profile->photo_url = $saved['url']; // set URL publik untuk dipakai klien
                Log::info('PROFILE_PHOTO_UPLOADED', [
                    'user_id' => $user->id,
                    'path'    => $saved['path'],
                    'url'     => $saved['url'],
                ]);
            }

            $profile->save();

            // muat relasi terkini
            $user->load('profile');

            return [
                'user'    => $user,
                'profile' => $user->profile,
            ];
        });

        return response()->json([
            'message' => 'Profile updated successfully',
            'data'    => $result,
        ]);
    }

    /**
     * POST /auth/register
     * Body: name, email, password, phone?, photo?(file)
     * Buat user + profile (role default customer), dan kembalikan token.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required','string','max:100'],
            'email'    => ['required','email','max:191', Rule::unique('users','email')],
            'password' => ['required','string','min:6'],
            'phone'    => ['nullable','string','max:30'],
            'photo'    => ['nullable', FileRule::image()->types(['jpg','jpeg','png','webp','heic'])->max(2 * 1024)],
        ]);

        $user = new User([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        $user->save();

        $uploaded = $this->saveProfileImage($request, ['photo','photo_url']);
        $photoUrl = $uploaded['url'] ?? null;

        Profile::create([
            'id'        => $user->id,
            'role'      => 'customer',
            'name'      => $data['name'],
            'phone'     => $data['phone'] ?? null,
            'is_online' => true,
            'photo_url' => $photoUrl,
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token'   => $token,
            'user'    => $user,
            'profile' => Profile::find($user->id),
        ], 201);
    }

    /**
     * POST /auth/register-mua
     * Sama seperti register, role = 'mua'
     */
    public function registerMua(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required','string','max:100'],
            'email'    => ['required','email','max:191', Rule::unique('users','email')],
            'password' => ['required','string','min:6'],
            'phone'    => ['nullable','string','max:30'],
            'photo'    => ['nullable', FileRule::image()->types(['jpg','jpeg','png','webp','heic'])->max(2 * 1024)],
        ]);

        $user = new User([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        $user->save();

        $uploaded = $this->saveProfileImage($request, ['photo','photo_url']);
        $photoUrl = $uploaded['url'] ?? null;

        Profile::create([
            'id'        => $user->id,
            'role'      => 'mua',
            'name'      => $data['name'],
            'phone'     => $data['phone'] ?? null,
            'is_online' => true,
            'photo_url' => $photoUrl,
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token'   => $token,
            'user'    => $user,
            'profile' => Profile::find($user->id),
        ], 201);
    }

    /**
     * POST /auth/login
     * Body: email, password
     */
    public function login(Request $request)
    {
        $rid = (string) Str::uuid();

        Log::channel('auth')->info('LOGIN_ATTEMPT', [
            'rid'   => $rid,
            'email' => $this->maskEmail($request->input('email')),
            'ip'    => $request->ip(),
            'ua'    => $request->userAgent(),
        ]);

        try {
            $data = $request->validate([
                'email'    => ['required','email'],
                'password' => ['required','string'],
            ]);
        } catch (\Throwable $e) {
            Log::channel('auth')->warning('LOGIN_VALIDATION_FAILED', [
                'rid'     => $rid,
                'message' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Validasi gagal'], 422);
        }

        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            Log::channel('auth')->warning('LOGIN_FAILED', [
                'rid'   => $rid,
                'email' => $this->maskEmail($data['email']),
                'ip'    => $request->ip(),
            ]);
            return response()->json(['error' => 'Email atau password salah'], 422);
        }

        $plainToken = $user->createToken('api')->plainTextToken;
        $tokenId = explode('|', $plainToken)[0] ?? null;

        Log::channel('auth')->info('LOGIN_SUCCESS', [
            'rid'       => $rid,
            'userId'    => $user->id,
            'tokenId'   => $tokenId,
            'ip'        => $request->ip(),
            'ua_hash'   => hash('sha256', (string) $request->userAgent()),
            'profileId' => optional($user->profile)->id,
        ]);

        return response()->json([
            'token'   => $plainToken,
            'user'    => $user,
            'profile' => $user->profile,
        ]);
    }

    private function maskEmail(?string $email): ?string
    {
        if (!$email || !str_contains($email, '@')) return $email;
        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));
        return $visible . '***@' . $domain;
    }

    /**
     * POST /auth/logout[?all=true]
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        if ($request->boolean('all')) {
            $user?->tokens()?->delete();
        } else {
            $token = $user?->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            } else {
                Auth::guard('web')->logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * GET /auth/me
     * ?with=offerings,portfolios,bookingsAsMua,bookingsAsCustomer,notifications&limit=50
     */
    public function me(Request $request)
    {
        $uid = $request->user()->id;

        $with  = collect(explode(',', (string) $request->query('with', '')))
            ->map(fn($s) => trim($s))
            ->filter();
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min(200, $limit));

        $profile = DB::selectOne(
            'SELECT id, role, name, phone, bio, photo_url, services, location_lat, location_lng, address, is_online, created_at, updated_at
             FROM profiles WHERE id = ? LIMIT 1',
            [$uid]
        );
        if (!$profile) {
            return response()->json(['message' => 'Profile not found'], 404);
        }

        // decode JSON services
        $services = null;
        if (is_array($profile->services)) {
            $services = $profile->services;
        } elseif (is_string($profile->services) && $profile->services !== '') {
            $decoded = json_decode($profile->services, true);
            $services = is_array($decoded) ? $decoded : null;
        }

        $counts = DB::selectOne(
            'SELECT
                (SELECT COUNT(*) FROM offerings    o WHERE o.mua_id      = ?) AS offerings,
                (SELECT COUNT(*) FROM portfolios   p WHERE p.mua_id      = ?) AS portfolios,
                (SELECT COUNT(*) FROM bookings     b WHERE b.mua_id      = ?) AS bookings_as_mua,
                (SELECT COUNT(*) FROM bookings     b WHERE b.customer_id = ?) AS bookings_as_customer,
                (SELECT COUNT(*) FROM notifications n WHERE n.user_id = ? AND n.is_read = 0) AS notifications_unread',
            [$uid, $uid, $uid, $uid, $uid]
        );

        $relations = [];

        if ($with->contains('offerings')) {
            $relations['offerings'] = DB::select(
                'SELECT id, mua_id, name_offer, offer_pictures, makeup_type, person, collaboration, collaboration_price, add_ons, price, created_at, updated_at
                 FROM offerings WHERE mua_id = ?
                 ORDER BY created_at DESC
                 LIMIT '.$limit,
                [$uid]
            );
        }

        if ($with->contains('portfolios')) {
            $relations['portfolios'] = DB::select(
                'SELECT id, mua_id, name, photos, makeup_type, collaboration, created_at, updated_at
                 FROM portfolios WHERE mua_id = ?
                 ORDER BY created_at DESC
                 LIMIT '.$limit,
                [$uid]
            );
        }

        if ($with->contains('bookingsAsMua')) {
            $relations['bookingsAsMua'] = DB::select(
                'SELECT id, customer_id, mua_id, offering_id, booking_date, booking_time, service_type,
                        location_address, notes, tax, total, status, payment_method, amount, payment_status,
                        invoice_number, invoice_date, due_date, selected_add_ons, discount_amount, tax_amount, subtotal, grand_total,
                        created_at, updated_at
                 FROM bookings WHERE mua_id = ?
                 ORDER BY created_at DESC
                 LIMIT '.$limit,
                [$uid]
            );
        }

        if ($with->contains('bookingsAsCustomer')) {
            $relations['bookingsAsCustomer'] = DB::select(
                'SELECT id, customer_id, mua_id, offering_id, booking_date, booking_time, service_type,
                        location_address, notes, tax, total, status, payment_method, amount, payment_status,
                        invoice_number, invoice_date, due_date, selected_add_ons, discount_amount, tax_amount, subtotal, grand_total,
                        created_at, updated_at
                 FROM bookings WHERE customer_id = ?
                 ORDER BY created_at DESC
                 LIMIT '.$limit,
                [$uid]
            );
        }

        if ($with->contains('notifications')) {
            $relations['notifications'] = DB::select(
                'SELECT id, user_id, title, message, type, is_read, created_at, updated_at
                 FROM notifications WHERE user_id = ?
                 ORDER BY created_at DESC
                 LIMIT '.$limit,
                [$uid]
            );
        }

        $payload = [
            'id'            => $profile->id,
            'role'          => $profile->role,
            'name'          => $profile->name,
            'phone'         => $profile->phone,
            'bio'           => $profile->bio,
            'photo_url'     => $profile->photo_url,
            'services'      => $services,
            'location_lat'  => $profile->location_lat,
            'location_lng'  => $profile->location_lng,
            'address'       => $profile->address,
            'is_online'     => (bool) $profile->is_online,
            'created_at'    => $profile->created_at,
            'updated_at'    => $profile->updated_at,

            'counts'        => [
                'offerings'             => (int) ($counts->offerings ?? 0),
                'portfolios'            => (int) ($counts->portfolios ?? 0),
                'bookings_as_mua'       => (int) ($counts->bookings_as_mua ?? 0),
                'bookings_as_customer'  => (int) ($counts->bookings_as_customer ?? 0),
                'notifications_unread'  => (int) ($counts->notifications_unread ?? 0),
            ],

            // kompat lama
            'profile'       => [
                'id'            => $profile->id,
                'role'          => $profile->role,
                'name'          => $profile->name,
                'phone'         => $profile->phone,
                'bio'           => $profile->bio,
                'photo_url'     => $profile->photo_url,
                'services'      => $services,
                'location_lat'  => $profile->location_lat,
                'location_lng'  => $profile->location_lng,
                'address'       => $profile->address,
                'is_online'     => (bool) $profile->is_online,
                'created_at'    => $profile->created_at,
                'updated_at'    => $profile->updated_at,
            ],

            'relations'     => (object) $relations,
        ];

        return response()->json($payload);
    }

    /**
     * PATCH /auth/profile/online
     * Body: is_online (boolean)
     */
    public function toggleOnline(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'is_online' => ['required','boolean'],
        ]);

        $profile = Profile::findOrFail($user->id);
        $profile->is_online = $data['is_online'];
        $profile->save();

        return response()->json([
            'profile' => $profile,
        ]);
    }

    /**
     * POST /auth/password
     * Body: current_password, new_password, new_password_confirmation
     */
    public function changeProfile(Request $request)
    {
        $data = $request->validate([
            'current_password'      => ['required','string'],
            'new_password'          => ['required','string','min:6','confirmed'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();
        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json(['error' => 'Password saat ini tidak sesuai'], 422);
        }

        $user->password = Hash::make($data['new_password']);
        $user->save();

        // Revoke token lama
        $user->tokens()->delete();

        $token = $user->createToken('api')->plainTextToken;

        return response()->json(['ok'=>true, 'token'=>$token]);
    }
}
