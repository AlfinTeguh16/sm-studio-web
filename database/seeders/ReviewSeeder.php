<?php

namespace Database\Seeders;

use App\Models\{Booking, Review};
use Illuminate\Database\Seeder;
use Faker\Factory as Faker;

class ReviewSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');

        $completed = Booking::where('status','completed')->get();
        foreach ($completed as $b) {
            // 80% booking yang selesai akan punya review
            if (!$faker->boolean(80)) continue;

            Review::updateOrCreate(
                ['booking_id' => $b->id],
                [
                    'customer_id' => $b->customer_id,
                    'mua_id'      => $b->mua_id,
                    'rating'      => $faker->numberBetween(4,5),
                    'comment'     => $faker->sentence(12),
                ]
            );
        }
    }
}
