<?php

namespace Database\Seeders;

use App\Models\{Profile, Offering, Availability, Booking, Notification};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;

class BookingSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');

        $customers = Profile::where('role','customer')->pluck('id')->all();
        $muas      = Profile::where('role','mua')->pluck('id')->all();

        if (empty($customers) || empty($muas)) return;

        $createdActiveKeys = []; // untuk cegah bentrok unique index active (pending|confirmed)
        $target = 80;

        for ($i=0; $i<$target; $i++) {
            $muaId = $faker->randomElement($muas);
            $avail = Availability::where('mua_id', $muaId)->inRandomOrder()->first();
            if (!$avail || empty($avail->time_slots)) continue;

            $date = $avail->available_date->toDateString();
            $time = $faker->randomElement($avail->time_slots);
            $key  = "{$muaId}|{$date}|{$time}";

            // Tentukan status (lebih banyak pending/confirmed)
            $statusPool = ['pending','pending','confirmed','confirmed','rejected','cancelled','completed'];
            $status = $faker->randomElement($statusPool);

            // Cegah bentrok hanya untuk status aktif
            if (in_array($status, ['pending','confirmed']) && isset($createdActiveKeys[$key])) {
                // skip dan coba ulang
                $i--;
                continue;
            }

            $offering = Offering::where('mua_id', $muaId)->inRandomOrder()->first();
            $amount   = $offering ? (float) $offering->price : (float) $faker->numberBetween(250000, 2500000);
            if ($offering && $offering->collaboration && $offering->collaboration_price) {
                if ($faker->boolean) $amount += (float) $offering->collaboration_price;
            }

            $paymentStatus = ($status === 'completed' || ($status === 'confirmed' && $faker->boolean))
                ? 'paid' : 'unpaid';

            $booking = Booking::create([
                'customer_id'    => $faker->randomElement($customers),
                'mua_id'         => $muaId,
                'offering_id'    => $offering?->id,
                'booking_date'   => $date,
                'booking_time'   => $time,
                'service_type'   => $faker->randomElement(['home_service','studio']),
                'status'         => $status,
                'amount'         => $amount,
                'payment_status' => $paymentStatus,
                'payment_provider'  => 'midtrans',
                'payment_reference' => 'MD-'.strtoupper($faker->bothify('????-########')),
                'payment_token'     => $faker->md5(),
                'payment_metadata'  => [
                    'seed' => $faker->uuid(),
                ],
                'paid_at'        => $paymentStatus === 'paid' ? now()->subDays(rand(0,7)) : null,
            ]);

            if (in_array($status, ['pending','confirmed'])) $createdActiveKeys[$key] = true;

            // Notif ke MUA
            Notification::create([
                'user_id' => $muaId,
                'title'   => 'Booking baru',
                'message' => "Ada booking baru pada {$date} {$time}",
                'type'    => 'booking',
            ]);
        }
    }
}
