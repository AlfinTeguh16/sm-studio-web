<?php

namespace Database\Seeders;

use App\Models\{Profile, Availability};
use Illuminate\Database\Seeder;

class AvailabilitySeeder extends Seeder
{
    public function run(): void
    {
        $muas = Profile::where('role','mua')->get();
        $baseSlots = ['08:00','09:30','11:00','13:00','14:30','16:00'];

        foreach ($muas as $mua) {
            // 14 hari ke depan
            for ($d=0; $d<14; $d++) {
                $date = now()->addDays($d)->toDateString();
                // ambil subset random dari base slots
                $count = rand(3, count($baseSlots));
                $slots = collect($baseSlots)->shuffle()->take($count)->sort()->values()->all();

                Availability::updateOrCreate(
                    ['mua_id'=>$mua->id, 'available_date'=>$date],
                    ['time_slots' => $slots]
                );
            }
        }
    }
}
