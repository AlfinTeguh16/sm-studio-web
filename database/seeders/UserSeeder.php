<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Profile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Faker\Factory as Faker;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');

        // --- 1) Admin ---
        $adminId = (string) Str::uuid();
        User::create([
            'id'       => $adminId,
            'name'     => 'Admin',
            'email'    => 'admin@smstudio.my.id',
            'password' => Hash::make('password'),
        ]);
        Profile::create([
            'id'        => $adminId,   // samakan dengan users.id
            'role'      => 'admin',
            'name'      => 'Admin',
            'phone'     => '081234567890',
            'services'  => [],
            'is_online' => true,
        ]);

        // --- 2) MUA: SM Studio ---
        $muaId = (string) Str::uuid();
        User::create([
            'id'       => $muaId,
            'name'     => 'SM Studio',
            'email'    => 'mua@smstudio.my.id',
            'password' => Hash::make('password'),
        ]);
        Profile::create([
            'id'           => $muaId,
            'role'         => 'mua',
            'name'         => 'SM Studio',
            'phone'        => '08'.mt_rand(1000000000, 9999999999),
            'bio'          => 'Professional MUA — bridal, party, engagement.',
            'photo_url'    => null, // isi URL bila perlu
            'services'     => ['bridal','party','engagement'],
            'location_lat' => $faker->latitude(-8.9, -8.0),   // contoh koordinat Bali
            'location_lng' => $faker->longitude(114.4, 115.7),
            'address'      => 'Bali, Indonesia',
            'is_online'    => true,
        ]);

        // --- 3) Customer/User ---
        $customerId = (string) Str::uuid();
        User::create([
            'id'       => $customerId,
            'name'     => 'User Demo',
            'email'    => 'user@smstudio.my.id',
            'password' => Hash::make('password'),
        ]);
        Profile::create([
            'id'        => $customerId,
            'role'      => 'customer',
            'name'      => 'User Demo',
            'phone'     => '08'.mt_rand(1000000000, 9999999999),
            'is_online' => true,
        ]);
    }
}
