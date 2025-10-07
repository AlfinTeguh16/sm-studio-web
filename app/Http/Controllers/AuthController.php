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

        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $filename = (string) Str::uuid() . '.' . $ext;

        $dest = public_path('profile_picture');
        if (!File::exists($dest)) {
            File::makeDirectory($dest, 0755, true);
        }

        // Pindahkan file ke public/profile_picture
        $file->move($dest, $filename);

        // URL absolut dari APP_URL
        $base = rtrim(config('app.url'), '/'); // APP_URL
        return $base . '/profile_picture/' . $filename;
    }

    /**
     * Hapus file lama di public/profile_picture jika URL menunjuk ke sana.
     */
    protected function deleteOldPublicPicture(?string $url): void
    {
        if (!$url) return;

        $base = rtrim(config('app.url'), '/'); // APP_URL
        $prefix = $base . '/profile_picture/';

        if (str_starts_with($url, $prefix)) {
            $filename = substr($url, strlen($prefix));
            $fullPath = public_path('profile_picture/' . $filename);
            if (File::exists($fullPath)) {
                File::delete($fullPath);
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
        $data = $request->validate([
            'email'       => ['required','email'],
            'password'    => ['required','string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['error' => 'Email atau password salah'], 422);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token'   => $token,
            'user'    => $user,
            'profile' => $user->profile,
        ]);
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
     * PUT/PATCH /auth/profile  (+ POST multipart _method=PATCH)
     * Update profile milik user login.
     * Field: name, phone, bio, address, services[], location_lat, location_lng, photo (file)
     */
    public function updateProfile(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        // Validasi
        $data = $request->validate([
            'name'          => ['nullable','string','max:100'],
            'phone'         => ['nullable','string','max:30'],
            'bio'           => ['nullable','string','max:1000'],
            'address'       => ['nullable','string','max:255'],
            'services'      => ['nullable','array'],
            'services.*'    => ['string','max:100'],
            'location_lat'  => ['nullable','numeric','between:-90,90'],
            'location_lng'  => ['nullable','numeric','between:-180,180'],

            // file optional; nama field "photo"
            'photo_url'         => ['nullable', FileRule::image()->types(['jpg','jpeg','png','webp','heic'])->max(2 * 1024)],
        ]);

        // Sinkron nama di tabel users
        if (array_key_exists('name', $data) && $data['name'] !== null) {
            $user->name = $data['name'];
            $user->save();
        }

        // Ambil/siapkan profile
        $profile = Profile::find($user->id);
        if (!$profile) {
            $profile = new Profile(['id' => $user->id, 'role' => 'customer', 'is_online' => true]);
        }

        // Update kolom teks
        foreach (['name','phone','address','bio','location_lat','location_lng'] as $col) {
            if (array_key_exists($col, $data)) {
                $profile->{$col} = $data[$col];
            }
        }
        if (array_key_exists('services', $data)) {

            $profile->services = $data['services']; 
        }

        // Handle upload foto (optional)
        if ($request->hasFile('photo_url')) {
            if (!empty($profile->photo_url)) {
                $this->deleteOldPublicPicture($profile->photo_url);
            }
            $newUrl = $this->storeProfilePhoto($request); // APP_URL/profile_picture/xxx.ext
            $profile->photo_url = $newUrl;
        }

        $profile->save();

        return response()->json([
            'ok' => true,
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'profile' => [
                'id'           => $profile->id,
                'role'         => $profile->role,
                'name'         => $profile->name,
                'phone'        => $profile->phone,
                'address'      => $profile->address,
                'bio'          => $profile->bio,
                'services'     => $profile->services,
                'location_lat' => $profile->location_lat,
                'location_lng' => $profile->location_lng,
                'photo_url'    => $profile->photo_url,
                'is_online'    => (bool) $profile->is_online,
                'created_at'   => $profile->created_at,
                'updated_at'   => $profile->updated_at,
            ],
        ]);
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
