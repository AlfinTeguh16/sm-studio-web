<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\Booking;
use App\Models\Offering;

class BookingSeeder extends Seeder
{
    public function run(): void
    {
        DB::beginTransaction();

        try {
            // ========== PROFILES ==========
            $muaA = Str::uuid()->toString();
            $muaB = Str::uuid()->toString();
            $cust1 = Str::uuid()->toString();
            $cust2 = Str::uuid()->toString();

            DB::table('profiles')->insert([
                [
                    'id' => $muaA, 'role' => 'mua', 'name' => 'MUA A',
                    'phone' => '081234567890', 'bio' => 'Natural & Wedding',
                    'photo_url' => null, 'services' => json_encode(['wedding','party','photoshoot']),
                    'location_lat' => -8.6500000, 'location_lng' => 115.2166667,
                    'address' => 'Denpasar, Bali', 'is_online' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ],
                [
                    'id' => $muaB, 'role' => 'mua', 'name' => 'MUA B',
                    'phone' => '081298765432', 'bio' => 'Glam & Photoshoot',
                    'photo_url' => null, 'services' => json_encode(['glam','editorial']),
                    'location_lat' => -8.5069000, 'location_lng' => 115.2625000,
                    'address' => 'Ubud, Bali', 'is_online' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ],
                [
                    'id' => $cust1, 'role' => 'customer', 'name' => 'Customer One',
                    'phone' => '081200000001', 'bio' => null, 'photo_url' => null,
                    'services' => null, 'location_lat' => null, 'location_lng' => null,
                    'address' => 'Gianyar', 'is_online' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ],
                [
                    'id' => $cust2, 'role' => 'customer', 'name' => 'Customer Two',
                    'phone' => '081200000002', 'bio' => null, 'photo_url' => null,
                    'services' => null, 'location_lat' => null, 'location_lng' => null,
                    'address' => 'Badung', 'is_online' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ],
            ]);

            // ========== AVAILABILITY ==========
            DB::table('availability')->insert([
                [
                    'mua_id' => $muaA,
                    'available_date' => Carbon::now()->addDay()->toDateString(),
                    'time_slots' => json_encode(['09:00','11:00','13:00']),
                    'created_at' => now(), 'updated_at' => now(),
                ],
                [
                    'mua_id' => $muaB,
                    'available_date' => Carbon::now()->addDays(2)->toDateString(),
                    'time_slots' => json_encode(['10:00','14:00']),
                    'created_at' => now(), 'updated_at' => now(),
                ],
            ]);

            // ========== OFFERINGS ==========
            $offer1 = DB::table('offerings')->insertGetId([
                'mua_id' => $muaA,
                'name_offer' => 'Wedding Natural',
                'offer_pictures' => json_encode([]),
                'makeup_type' => 'wedding',
                'person' => 1,
                'collaboration' => null,
                'collaboration_price' => null,
                'add_ons' => json_encode([
                    ['name' => 'Extra Hour', 'price' => 75000],
                    ['name' => 'Premium Tools', 'price' => 50000],
                ]),
                'date' => null,
                'price' => 350000,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $offer2 = DB::table('offerings')->insertGetId([
                'mua_id' => $muaA,
                'name_offer' => 'Party Glam',
                'offer_pictures' => json_encode([]),
                'makeup_type' => 'party',
                'person' => 1,
                'collaboration' => null,
                'collaboration_price' => null,
                'add_ons' => json_encode([
                    ['name' => 'Lashes', 'price' => 30000],
                ]),
                'date' => null,
                'price' => 250000,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $offer3 = DB::table('offerings')->insertGetId([
                'mua_id' => $muaB,
                'name_offer' => 'Editorial Shoot',
                'offer_pictures' => json_encode([]),
                'makeup_type' => 'editorial',
                'person' => 1,
                'collaboration' => null,
                'collaboration_price' => null,
                'add_ons' => json_encode([]),
                'date' => null,
                'price' => 400000,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // ========== BOOKINGS (INVOICE) ==========
            // Booking 1 — unpaid, pending
            $b1 = new Booking();
            $b1->setAttribute('customer_id', $cust1);
            $b1->setAttribute('mua_id', $muaA);
            $b1->setAttribute('offering_id', $offer1);
            $b1->setAttribute('booking_date', Carbon::now()->addDay());     // besok
            $b1->setAttribute('booking_time', '11:00');
            $b1->setAttribute('service_type', 'home_service');
            $b1->setAttribute('location_address', 'Jl. Mawar No.10, Denpasar');
            $b1->setAttribute('notes', 'Datang 15 menit lebih awal');
            $b1->setAttribute('invoice_date', Carbon::now());
            $b1->setAttribute('due_date', Carbon::now()->addDays(3));
            $b1->setAttribute('amount', '350000'); // string untuk decimal:2
            $b1->setAttribute('selected_add_ons', [
                ['name' => 'Extra Hour', 'price' => 75000],
                ['name' => 'Premium Tools', 'price' => 50000],
            ]);
            $b1->setAttribute('discount_amount', '25000');
            $b1->setAttribute('tax', '11'); // persen (legacy, akan dihitung di computeTotals)
            $b1->setAttribute('payment_status', 'unpaid');
            $b1->setAttribute('job_status', 'pending');
            $b1->computeTotals($b1->getAttribute('selected_add_ons'));
            $b1->save();

            // Booking 2 — partial, in_progress
            $b2 = new Booking();
            $b2->setAttribute('customer_id', $cust2);
            $b2->setAttribute('mua_id', $muaA);
            $b2->setAttribute('offering_id', $offer2);
            $b2->setAttribute('booking_date', Carbon::now()->addDays(2));
            $b2->setAttribute('booking_time', '10:00');
            $b2->setAttribute('service_type', 'studio');
            $b2->setAttribute('location_address', 'Studio A, Denpasar');
            $b2->setAttribute('notes', null);
            $b2->setAttribute('invoice_date', Carbon::now());
            $b2->setAttribute('due_date', Carbon::now()->addDays(2));
            $b2->setAttribute('amount', '250000');
            $b2->setAttribute('selected_add_ons', [['name' => 'Lashes', 'price' => 30000]]);
            $b2->setAttribute('discount_amount', '0');
            $b2->setAttribute('tax', '11');
            $b2->setAttribute('payment_status', 'partial');
            $b2->setAttribute('paid_at', Carbon::now());
            $b2->setAttribute('job_status', 'in_progress');
            $b2->computeTotals($b2->getAttribute('selected_add_ons'));
            $b2->save();

            // Booking 3 — paid, completed
            $b3 = new Booking();
            $b3->setAttribute('customer_id', $cust1);
            $b3->setAttribute('mua_id', $muaB);
            $b3->setAttribute('offering_id', $offer3);
            $b3->setAttribute('booking_date', Carbon::now()->subDay()); // kemarin (sudah selesai)
            $b3->setAttribute('booking_time', '14:00');
            $b3->setAttribute('service_type', 'studio');
            $b3->setAttribute('location_address', 'Studio B, Ubud');
            $b3->setAttribute('notes', 'Background putih');
            $b3->setAttribute('invoice_date', Carbon::now()->subDays(2));
            $b3->setAttribute('due_date', Carbon::now()->subDay());
            $b3->setAttribute('amount', '400000');
            $b3->setAttribute('selected_add_ons', []);
            $b3->setAttribute('discount_amount', '0');
            $b3->setAttribute('tax', '11');
            $b3->setAttribute('payment_status', 'paid');
            $b3->setAttribute('paid_at', Carbon::now()->subDay());
            $b3->setAttribute('job_status', 'completed');
            $b3->computeTotals($b3->getAttribute('selected_add_ons'));
            $b3->save();

            // ========== REVIEWS (opsional) ==========
            DB::table('reviews')->insert([
                [
                    'booking_id' => $b3->getKey(),
                    'customer_id' => $cust1,
                    'mua_id' => $muaB,
                    'rating' => 5,
                    'comment' => 'Makeup rapi, tepat waktu. Recommended!',
                    'created_at' => now(), 'updated_at' => now(),
                ],
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->command?->error('Seeder gagal: '.$e->getMessage());
            throw $e;
        }
    }
}
