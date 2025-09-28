<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            OfferingSeeder::class,
            PortfolioSeeder::class,
            AvailabilitySeeder::class,
            BookingSeeder::class,
            ReviewSeeder::class,
            NotificationSeeder::class,
        ]);
    }
}
