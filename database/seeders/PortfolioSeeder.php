<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PortfolioSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Pastikan tabel yang dibutuhkan tersedia
        if (!Schema::hasTable('profiles')) {
            $this->command?->warn('[PortfolioSeeder] Skip: tabel "profiles" belum ada. Jalankan migrasi dulu.');
            return;
        }
        if (!Schema::hasTable('portfolios')) {
            $this->command?->warn('[PortfolioSeeder] Skip: tabel "portfolios" belum ada. Jalankan migrasi dulu.');
            return;
        }

        // 2) Ambil/siapkan minimal 1 MUA
        $mua = DB::table('profiles')->where('role', 'mua')->first();

        if (!$mua) {
            $muaId = (string) Str::uuid();
            DB::table('profiles')->insert([
                'id'            => $muaId,
                'role'          => 'mua',
                'name'          => 'Seeder MUA',
                'phone'         => '081200000000',
                'bio'           => 'MUA seeder profile',
                'photo_url'     => null,
                'services'      => json_encode(['wedding','party']),
                'location_lat'  => null,
                'location_lng'  => null,
                'address'       => 'Denpasar',
                'is_online'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
            $mua = (object) ['id' => $muaId];
        }

        // 3) Insert beberapa portfolio sample
        $now = now();
        $rows = [
            [
                'mua_id'       => $mua->id,
                'name'         => 'Wedding – Indah',
                'photos'       => json_encode([
                    'https://picsum.photos/seed/qrANnQ/900/1200',
                    'https://picsum.photos/seed/jLwGsp/900/1200',
                    'https://picsum.photos/seed/9ZaiWt/900/1200',
                ]),
                'makeup_type'  => 'bridal',
                'collaboration'=> 'Yayasan Laksmiwati',
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [
                'mua_id'       => $mua->id,
                'name'         => 'Party Glam – Dina',
                'photos'       => json_encode([
                    'https://picsum.photos/seed/Zv8pQz/900/1200',
                    'https://picsum.photos/seed/Qk3aLp/900/1200',
                ]),
                'makeup_type'  => 'party',
                'collaboration'=> null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
        ];

        DB::table('portfolios')->insert($rows);

        $this->command?->info('[PortfolioSeeder] 2 portfolio berhasil dibuat.');
    }
}
