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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Simpan foto profil ke public/profile_picture dan kembalikan URL absolut (APP_URL/profile_picture/xxx.ext).
     */
    protected function storeProfilePhoto(Request $request): ?string
    {
        if (!$request->hasFile('photo')) return null;

        $file = $request->file('photo');
        if (!$file->isValid()) return null;

        // buat folder kalau belum ada
        $dest = public_path('profile_picture');
        if (!File::exists($dest)) {
            File::makeDirectory($dest, 0755, true);
        }

        // nama file unik
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $filename = (string) Str::uuid() . '.' . $ext;

        // pindahkan ke public/profile_picture
        $file->move($dest, $filename);

        // kembalikan URL absolut untuk kolom photo_url
        $base = rtrim(config('app.url'), '/');
        return $base . '/profile_picture/' . $filename;
    }

    public function updateProfile(Request $request)
    {
        // --- 1) Validasi input ---
        $data = $request->validate([
            'name'          => ['nullable','string','max:100'],
            'phone'         => ['nullable','string','max:30'],
            'bio'           => ['nullable','string','max:1000'],
            'address'       => ['nullable','string','max:255'],
            'services'      => ['nullable'], // bisa array atau string CSV, kita normalize di bawah
            'services.*'    => ['sometimes','string','max:100'],
            'location_lat'  => ['nullable','numeric','between:-90,90'],
            'location_lng'  => ['nullable','numeric','between:-180,180'],

            'photo_url'         => ['nullable', FileRule::image()->types(['jpg','jpeg','png','webp','heic'])->max(2 * 1024)],
            // kalau ingin paksa hapus foto lama
            'remove_photo'  => ['nullable','boolean'],
        ]);

        // dd($data);

        $user = $request->user();

        // --- 2) Normalisasi services: izinkan string "a,b,c" atau array ["a","b","c"] ---
        if (array_key_exists('services', $data) && $data['services'] !== null) {
            if (is_string($data['services'])) {
                // pecah CSV & trim
                $items = array_filter(array_map(fn($s) => trim($s), explode(',', $data['services'])));
                $data['services'] = array_values($items);
            } elseif (is_array($data['services'])) {
                // pastikan semua string & trim
                $data['services'] = array_values(array_filter(array_map(fn($s) => is_string($s) ? trim($s) : $s, $data['services']), fn($s) => $s !== ''));
            } else {
                $data['services'] = null;
            }
        }

        // --- 3) Eksekusi dalam transaksi agar konsisten ---
        $result = DB::transaction(function () use ($data, $user, $request) {

            // 3a) Sinkron nama user (opsional jika diisi)
            if (array_key_exists('name', $data) && $data['name'] !== null) {
                $user->name = $data['name'];
                $user->save();
            }

            // 3b) Ambil / buat profile dengan PK = user id (UUID)
            $profile = Profile::firstOrNew(['id' => $user->id]);
            // Default role jika belum ada
            if (!$profile->exists && empty($profile->role)) {
                $profile->role = 'customer';
            }

            // 3c) Isi field yang ada di profiles
            foreach (['phone','bio','address','location_lat','location_lng'] as $f) {
                if (array_key_exists($f, $data)) {
                    $profile->{$f} = $data[$f];
                }
            }
            if (array_key_exists('services', $data)) {
                $profile->services = $data['services']; // cast ke array oleh model
            }

            // 3d) Kelola foto: hapus, ganti, atau biarkan
            $removePhoto = (bool)($data['remove_photo'] ?? false);
            if ($removePhoto && $profile->photo_url) {
                // Hapus file lama jika berasal dari storage public (opsional: validasi path)
                $this->deleteOldPhotoIfOwned($profile->photo_url);
                $profile->photo_url = null;
            }

            if ($request->hasFile('photo_url')) {
                $uploaded = $request->file('photo_url');

                // Simpan file ke disk 'public' => storage/app/public/profile_photos/xxxxxx.ext
                $path = $uploaded->store('profile_photos', 'public');

                // Hapus foto lama (jika ada) setelah unggah baru sukses
                if (!empty($profile->photo_url)) {
                    $this->deleteOldPhotoIfOwned($profile->photo_url);
                }

                // Set URL publik (via storage:link)
                $path = $request->file('photo_url')->store('profile_photos', 'public');
                $publicUrl = Storage::url($path);

            }

            $profile->save();

            // Muat relasi terkini
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
     * Hapus file lama jika berada pada disk 'public' dan path termasuk 'profile_photos/'.
     * Ini untuk mencegah menghapus URL eksternal yang bukan milik storage kita.
     */
    protected function deleteOldPhotoIfOwned(?string $photoUrl): void
    {
        if (!$photoUrl) return;

        // Contoh URL: /storage/profile_photos/xxx.jpg atau http(s)://domain/storage/profile_photos/xxx.jpg
        // Ambil bagian setelah '/storage/'
        $storagePos = strpos($photoUrl, '/storage/');
        if ($storagePos === false) {
            return; // bukan dari storage publik
        }

        $relative = substr($photoUrl, $storagePos + strlen('/storage/')); // profile_photos/xxx.jpg
        if (str_starts_with($relative, 'profile_photos/')) {
            // Pastikan file ada sebelum delete
            if (Storage::disk('public')->exists($relative)) {
                Storage::disk('public')->delete($relative);
            }
        }
    }

    /**
     * POST /auth/register
     * Body: name, email, password, phone (opsional), photo (opsional)
     * Buat user + profile (role default: customer), lalu kembalikan token.
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

        $photoUrl = $this->storeProfilePhoto($request);

        Profile::create([
            'id'        => $user->id,          // UUID yang sama dengan users.id
            'role'      => 'customer',
            'name'      => $data['name'],
            'phone'     => $data['phone'] ?? null,
            'is_online' => true,
            'photo_url' => $photoUrl,          // APP_URL/profile_picture/xxxx.ext
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
     * Sama seperti register, tetapi role = 'mua'
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

        $photoUrl = $this->storeProfilePhoto($request);

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
        // Generate request id untuk membantu trace di log
        $rid = (string) Str::uuid();

        // Catat attempt awal (email dimasking)
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
                // Jangan log input sensitif
            ]);

            return response()->json(['error' => 'Validasi gagal'], 422);
        }

        $user = User::where('email', $data['email'])->first();

        if (!$user) {
            Log::channel('auth')->warning('LOGIN_FAILED_NO_USER', [
                'rid'   => $rid,
                'email' => $this->maskEmail($data['email']),
                'ip'    => $request->ip(),
            ]);

            return response()->json(['error' => 'Email atau password salah'], 422);
        }

        if (!Hash::check($data['password'], $user->password)) {
            Log::channel('auth')->warning('LOGIN_FAILED_BAD_PASSWORD', [
                'rid'    => $rid,
                'userId' => $user->id,
                'ip'     => $request->ip(),
            ]);

            return response()->json(['error' => 'Email atau password salah'], 422);
        }

        $plainToken = $user->createToken('api')->plainTextToken;
        // Sanctum plain text: "{token_id}|{token_value}", ambil hanya ID untuk log
        $tokenId = explode('|', $plainToken)[0] ?? null;

        Log::channel('auth')->info('LOGIN_SUCCESS', [
            'rid'       => $rid,
            'userId'    => $user->id,
            'tokenId'   => $tokenId,
            'ip'        => $request->ip(),
            'ua_hash'   => hash('sha256', (string) $request->userAgent()), // optional: hash UA
            'profileId' => optional($user->profile)->id,
        ]);

        return response()->json([
            'token'   => $plainToken,
            'user'    => $user,
            'profile' => $user->profile,
        ]);
    }

    /**
     * Mask sebagian email untuk log.
     * contoh: johndoe@gmail.com -> jo***@gmail.com
     */
    private function maskEmail(?string $email): ?string
    {
        if (!$email || !str_contains($email, '@')) return $email;
        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));
        return $visible . '***@' . $domain;
    }

    /**
     * POST /auth/logout[?all=true]
     * Hapus token saat ini; jika ?all=true maka hapus semua token user.
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        if ($request->boolean('all')) {
            $user?->tokens()?->delete();
        } else {
            $token = $user?->currentAccessToken();

            if ($token instanceof PersonalAccessToken) {
                // Mode Bearer token
                $token->delete();
            } else {
                // Mode cookie/SPA (TransientToken) atau null
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
     * Ambil info user + profile + optional relations (?with=offerings,portfolios,bookingsAsMua,bookingsAsCustomer,notifications&limit=50)
     */
    public function me(Request $request)
    {
        $uid = $request->user()->id; // diasumsikan pakai Sanctum

        // parse query
        $with  = collect(explode(',', (string) $request->query('with', '')))
                    ->map(fn($s) => trim($s))
                    ->filter();
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min(200, $limit));

        // 1) Ambil profil (raw)
        $profile = DB::selectOne(
            'SELECT id, role, name, phone, bio, photo_url, services, location_lat, location_lng, address, is_online, created_at, updated_at
             FROM profiles WHERE id = ? LIMIT 1',
            [$uid]
        );
        if (!$profile) {
            return response()->json(['message' => 'Profile not found'], 404);
        }

        // decode JSON services → array
        $services = null;
        if (is_array($profile->services)) {
            $services = $profile->services;
        } elseif (is_string($profile->services) && $profile->services !== '') {
            $decoded = json_decode($profile->services, true);
            $services = is_array($decoded) ? $decoded : null;
        }

        // 2) Hitungan cepat (1 query, subselect)
        $counts = DB::selectOne(
            'SELECT
                (SELECT COUNT(*) FROM offerings    o WHERE o.mua_id      = ?) AS offerings,
                (SELECT COUNT(*) FROM portfolios   p WHERE p.mua_id      = ?) AS portfolios,
                (SELECT COUNT(*) FROM bookings     b WHERE b.mua_id      = ?) AS bookings_as_mua,
                (SELECT COUNT(*) FROM bookings     b WHERE b.customer_id = ?) AS bookings_as_customer,
                (SELECT COUNT(*) FROM notifications n WHERE n.user_id = ? AND n.is_read = 0) AS notifications_unread',
            [$uid, $uid, $uid, $uid, $uid]
        );

        // 3) Relasi opsional via ?with=
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

        // 4) Bentuk response (backward compatible: sediakan juga key "profile")
        $payload = [
            // field profil langsung
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

            // ringkasan
            'counts'        => [
                'offerings'             => (int) ($counts->offerings ?? 0),
                'portfolios'            => (int) ($counts->portfolios ?? 0),
                'bookings_as_mua'       => (int) ($counts->bookings_as_mua ?? 0),
                'bookings_as_customer'  => (int) ($counts->bookings_as_customer ?? 0),
                'notifications_unread'  => (int) ($counts->notifications_unread ?? 0),
            ],

            // kompatibilitas klien lama: me.profile.id
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

            // relasi yang diminta
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

        // Opsional: revoke semua token lama setelah ganti password
        $user->tokens()->delete();

        $token = $user->createToken('api')->plainTextToken;

        return response()->json(['ok'=>true, 'token'=>$token]);
    }
}
