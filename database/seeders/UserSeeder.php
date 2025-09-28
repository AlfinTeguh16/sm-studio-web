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

        // 1) Admin
        $adminId = (string) Str::uuid();
        User::create([
            'id'       => $adminId,
            'name'     => 'Admin',
            'email'    => 'admin@example.com',
            'password' => Hash::make('password'),
        ]);
        Profile::create([
            'id'        => $adminId,
            'role'      => 'admin',
            'name'      => 'Admin',
            'phone'     => '081234567890',
            'services'  => [],
            'is_online' => true,
        ]);

        // 2) MUAs
        for ($i=1; $i<=8; $i++) {
            $id = (string) Str::uuid();
            $name = 'MUA '.$faker->firstName().' '.$faker->lastName();
            User::create([
                'id'       => $id,
                'name'     => $name,
                'email'    => "mua{$i}@example.com",
                'password' => Hash::make('password'),
            ]);

            $skills = collect(['bridal','party','photoshoot','graduation','engagement','sfx'])
                ->shuffle()->take(rand(2,4))->values()->all();

            Profile::create([
                'id'          => $id,
                'role'        => 'mua',
                'name'        => $name,
                'phone'       => '08'.mt_rand(1000000000, 9999999999),
                'bio'         => $faker->sentence(10),
                'photo_url'   => 'https://picsum.photos/seed/'.Str::random(6).'/400/400',
                'services'    => $skills,
                'location_lat'=> $faker->latitude(-8, 2),
                'location_lng'=> $faker->longitude(95, 141),
                'address'     => $faker->address(),
                'is_online'   => (bool) rand(0,1),
            ]);
        }

        // 3) Customers
        for ($i=1; $i<=20; $i++) {
            $id = (string) Str::uuid();
            $name = $faker->name();
            User::create([
                'id'       => $id,
                'name'     => $name,
                'email'    => "customer{$i}@example.com",
                'password' => Hash::make('password'),
            ]);

            Profile::create([
                'id'        => $id,
                'role'      => 'customer',
                'name'      => $name,
                'phone'     => '08'.mt_rand(1000000000, 9999999999),
                'is_online' => true,
            ]);
        }
    }
}
