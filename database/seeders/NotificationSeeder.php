<?php

namespace Database\Seeders;

use App\Models\{Profile, Notification};
use Illuminate\Database\Seeder;
use Faker\Factory as Faker;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');

        // Tambahan notifikasi sistem untuk semua MUA & admin
        $targets = Profile::whereIn('role', ['mua','admin'])->pluck('id');
        foreach ($targets as $uid) {
            Notification::create([
                'user_id' => $uid,
                'title'   => 'Selamat datang',
                'message' => 'Dashboard siap digunakan. Cek penjadwalan dan offering Anda.',
                'type'    => 'system',
            ]);
            // 50% tambah notifikasi payment
            if ($faker->boolean) {
                Notification::create([
                    'user_id' => $uid,
                    'title'   => 'Info pembayaran',
                    'message' => 'Fitur pembayaran Midtrans (placeholder) aktif.',
                    'type'    => 'payment',
                ]);
            }
        }
    }
}
