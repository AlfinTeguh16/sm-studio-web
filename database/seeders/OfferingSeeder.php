<?php

namespace Database\Seeders;

use App\Models\{Profile, Offering};
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Faker\Factory as Faker;

class OfferingSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');

        $makeups = ['bridal','party','photoshoot','graduation','engagement','sfx'];
        $addons  = ['Hair styling','Retouch','Extra eyelashes','Touch up on site','Ampuh anti-oily'];

        $muas = Profile::where('role','mua')->get();

        foreach ($muas as $mua) {
            $count = rand(2,4);
            for ($i=0; $i<$count; $i++) {
                $hasCollab = (bool) rand(0,1);
                $collab    = $hasCollab ? $faker->company() : null;
                $collabPrice = $hasCollab ? $faker->numberBetween(200000, 750000) : null;

                Offering::create([
                    'mua_id'              => $mua->id,
                    'name_offer'          => $faker->randomElement(['Bridal Package','Party Glam','Photoshoot Look','Graduation Basic']).' #'.($i+1),
                    'offer_pictures'      => [
                        'https://picsum.photos/seed/'.Str::random(6).'/800/1000',
                        'https://picsum.photos/seed/'.Str::random(6).'/800/1000',
                    ],
                    'makeup_type'         => $faker->randomElement($makeups),
                    'collaboration'       => $collab,
                    'collaboration_price' => $collabPrice,
                    'add_ons'             => collect($addons)->shuffle()->take(rand(0,3))->values()->all(),
                    'price'               => $faker->numberBetween(250000, 3500000),
                ]);
            }
        }
    }
}
