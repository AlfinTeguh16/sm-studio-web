<?php

namespace Database\Seeders;

use App\Models\{Profile, Portfolio};
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Faker\Factory as Faker;

class PortfolioSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');
        $muas = Profile::where('role','mua')->get();

        foreach ($muas as $mua) {
            $count = rand(2,3);
            for ($i=0; $i<$count; $i++) {
                Portfolio::create([
                    'mua_id'      => $mua->id,
                    'name'        => $faker->randomElement(['Wedding','Engagement','Photoshoot','Party']).' – '.$faker->firstName(),
                    'photos'      => [
                        'https://picsum.photos/seed/'.Str::random(6).'/900/1200',
                        'https://picsum.photos/seed/'.Str::random(6).'/900/1200',
                        'https://picsum.photos/seed/'.Str::random(6).'/900/1200',
                    ],
                    'makeup_type' => $faker->randomElement(['bridal','party','photoshoot']),
                    'collaboration' => (rand(0,1) ? $faker->company() : null),
                ]);
            }
        }
    }
}
