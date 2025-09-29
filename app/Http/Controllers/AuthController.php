<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;


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
            $user = $request->user()->load('profile');
            return response()->json($user);
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
