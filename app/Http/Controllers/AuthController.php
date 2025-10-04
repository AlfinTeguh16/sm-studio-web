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



class AuthController extends Controller
{
    
        /**
         * POST /auth/register
         * Body: name, email, password, phone (opsional)
         * Buat user + profile (role default: customer), lalu kembalikan token.
         */
        public function register(Request $request)
        {
            $data = $request->validate([
                'name'     => ['required','string','max:100'],
                'email'    => ['required','email','max:191', Rule::unique('users','email')],
                'password' => ['required','string','min:6'],
                'phone'    => ['nullable','string','max:30'],
            ]);
    
            $user = new User([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'password' => Hash::make($data['password']),
            ]);
            $user->save();
    
            // Auto-create profile (role default customer)
            Profile::create([
                'id'        => $user->id,
                'role'      => 'customer',
                'name'      => $data['name'],
                'phone'     => $data['phone'] ?? null,
                'is_online' => true,
            ]);
    
            $token = $user->createToken('api')->plainTextToken;
    
            return response()->json([
                'token'   => $token,
                'user'    => $user,
                'profile' => Profile::find($user->id),
            ], 201);
        }
        public function registerMua(Request $request)
        {
            $data = $request->validate([
                'name'     => ['required','string','max:100'],
                'email'    => ['required','email','max:191', Rule::unique('users','email')],
                'password' => ['required','string','min:6'],
                'phone'    => ['nullable','string','max:30'],
            ]);
    
            $user = new User([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'password' => Hash::make($data['password']),
            ]);
            $user->save();
    
            // Auto-create profile (role default mua)
            Profile::create([
                'id'        => $user->id,
                'role'      => 'mua',
                'name'      => $data['name'],
                'phone'     => $data['phone'] ?? null,
                'is_online' => true,
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
         * Opsional: revoke_others=true untuk hapus token lama.
         */
        public function login(Request $request)
        {
            $data = $request->validate([
                'email'       => ['required','email'],
                'password'    => ['required','string'],
                'revoke_others' => ['nullable','boolean'],
            ]);
    
            $user = User::where('email', $data['email'])->first();
            if (!$user || !Hash::check($data['password'], $user->password)) {
                return response()->json(['error' => 'Email atau password salah'], 422);
            }
    
            // Opsional: revoke semua token lama
            if (!empty($data['revoke_others'])) {
                $user->tokens()->delete();
            }
    
            $token = $user->createToken('api')->plainTextToken;
    
            return response()->json([
                'token'   => $token,
                'user'    => $user,
                'profile' => $user->profile,
            ]);
        }
    
        /**
         * POST /auth/logout
         * Hapus token saat ini; jika ?all=true maka hapus semua token user.
         */
        public function logout(Request $request)
        {
            $user = $request->user();
        
            // Logout semua token personal (opsional via ?all=true)
            if ($request->boolean('all')) {
                // Berlaku untuk PersonalAccessToken (Bearer)
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
         * Ambil info user + profile
         */
        public function me(Request $request)
        {
            $uid = $request->user()->id; // diasumsikan pakai Sanctum & guard ke profiles

            // parse query: ?with=offerings,portfolios,bookingsAsMua,bookingsAsCustomer,notifications&limit=50
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
            if (!empty($profile->services)) {
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
                    'SELECT id, mua_id, name_offer, offer_pictures, makeup_type, person, collaboration, collaboration_price, add_ons, date, price, created_at, updated_at
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
         * PUT/PATCH /auth/profile
         * Update profile milik user login.
         * Field yang bisa diupdate: name, phone, bio, photo_url, address, services (array),
         * location_lat, location_lng, (opsional) role untuk admin (tapi DISABLE di sini).
         */
        public function updateProfile(Request $request)
        {
            $user = $request->user();
    
            $data = $request->validate([
                'name'          => ['nullable','string','max:100'],
                'phone'         => ['nullable','string','max:30'],
                'bio'           => ['nullable','string','max:1000'],
                'photo_url'     => ['nullable','string','max:500'],
                'address'       => ['nullable','string','max:255'],
                'services'      => ['nullable','array'],
                'services.*'    => ['string','max:100'],
                'location_lat'  => ['nullable','numeric','between:-90,90'],
                'location_lng'  => ['nullable','numeric','between:-180,180'],
            ]);
    
            // Sinkron nama user kalau diubah
            if (isset($data['name'])) {
                $user->name = $data['name'];
                $user->save();
            }
    
            $profile = Profile::findOrFail($user->id);
            $profile->fill($data);
            $profile->save();
    
            return response()->json([
                'user'    => $user,
                'profile' => $profile,
            ]);
        }
    
        /**
         * PATCH /auth/profile/online
         * Body: is_online (boolean)
         * Toggle status online/offline MUA (customer juga boleh, tapi berguna untuk MUA).
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
         * PATCH /auth/password
         * Body: current_password, new_password, new_password_confirmation
         */
        public function changePassword(Request $request)
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
