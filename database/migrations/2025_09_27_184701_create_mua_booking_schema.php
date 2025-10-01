<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
    {
        /**
         * PROFILES (UUID PK) — berisi role & status online/offline
         */
        Schema::create('profiles', function (Blueprint $table) {
            $table->uuid('id')->primary(); // dapat disamakan dengan users.id (UUID)
            $table->enum('role', ['customer','mua','admin'])->default('customer');
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->text('bio')->nullable();
            $table->string('photo_url')->nullable();
            $table->json('services')->nullable();       // list layanan/skill MUA
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_online')->default(true); // status MUA online/offline
            $table->timestamps();
        });

        /**
         * AVAILABILITY — ketersediaan MUA per tanggal & jam
         */
        Schema::create('availability', function (Blueprint $table) {
            $table->id();
            $table->uuid('mua_id');
            $table->date('available_date');
            $table->json('time_slots'); // contoh: ["08:00","09:30","13:00"]
            $table->timestamps();

            $table->unique(['mua_id','available_date']);
            $table->foreign('mua_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->index('available_date');
        });

        /**
         * OFFERINGS — layanan/paket yang dipublish MUA
         */
        Schema::create('offerings', function (Blueprint $table) {
            $table->id();
            $table->uuid('mua_id');
            $table->string('name_offer');
            $table->json('offer_pictures')->nullable(); // array URL foto
            $table->string('makeup_type')->nullable();
            $table->unsignedInteger('person')->default(1); // jumlah orang (>=1)
            $table->string('collaboration')->nullable();   // nama partner/brand (boleh null)
            $table->decimal('collaboration_price', 12, 2)->nullable(); // wajib isi jika collaboration ada
            $table->json('add_ons')->nullable();           // array string bebas
            $table->date('date')->nullable();              // opsional
            $table->decimal('price', 12, 2);
            $table->timestamps();

            $table->foreign('mua_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->index(['mua_id','price']);
        });

        // Enforce: jika collaboration NULL maka collaboration_price juga NULL, dan sebaliknya
        DB::statement(
            "ALTER TABLE offerings 
             ADD CONSTRAINT chk_collab_price 
             CHECK (
                (collaboration IS NULL AND collaboration_price IS NULL)
                OR (collaboration IS NOT NULL AND collaboration_price IS NOT NULL)
             )"
        );

        /**
         * BOOKINGS — pemesanan customer -> MUA
         */
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('customer_id');
            $table->uuid('mua_id');
            $table->foreignId('offering_id')->nullable()->constrained('offerings')->nullOnDelete();
            $table->date('booking_date');
            $table->string('booking_time', 5); // 'HH:MM'
            $table->enum('service_type', ['home_service','studio']);
            $table->string('location_address')->nullable(); // wajib jika service_type=home_service
            $table->text('notes')->nullable();
            $table->string('tax'); 
            $table->string('total')->nullable();
            $table->enum('status', ['pending','confirmed','rejected','cancelled','completed'])->default('pending');
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->enum('payment_status', ['unpaid','paid','refunded'])->default('unpaid');

            // Placeholder Midtrans (tanpa integrasi dulu)
            // $table->string('payment_provider')->nullable()->default('midtrans');
            // $table->string('payment_reference')->nullable(); // order_id/transaction_id
            // $table->string('payment_token')->nullable();     // snap_token/redirect token
            // $table->json('payment_metadata')->nullable();    // payload raw
            // $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->foreign('mua_id')->references('id')->on('profiles')->onDelete('cascade');

            $table->index(['mua_id','booking_date']);
            $table->index(['customer_id']);
        });

        // Cegah double-booking aktif (pending|confirmed) pakai generated column + unique index
        DB::statement("ALTER TABLE bookings 
                       ADD COLUMN active TINYINT 
                       AS (IF(status IN ('pending','confirmed'), 1, 0)) STORED");
        DB::statement("CREATE UNIQUE INDEX uq_mua_dt_time_active 
                       ON bookings (mua_id, booking_date, booking_time, active)");

        /**
         * REVIEWS — ulasan customer ke MUA untuk booking tertentu
         */
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->uuid('customer_id');
            $table->uuid('mua_id');
            $table->unsignedTinyInteger('rating'); // 1..5
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->foreign('mua_id')->references('id')->on('profiles')->onDelete('cascade');

            $table->index('mua_id');
            $table->index('customer_id');
        });

        // Enforce rating 1..5 (MySQL 8.0+)
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5)");

        /**
         * NOTIFICATIONS — notifikasi ke user
         */
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->string('title');
            $table->text('message');
            $table->enum('type', ['booking','system','payment'])->default('system');
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->index('user_id');
        });

        /**
         * PORTFOLIOS — karya MUA (banyak foto)
         */
        Schema::create('portfolios', function (Blueprint $table) {
            $table->id();
            $table->uuid('mua_id');
            $table->string('name');
            $table->json('photos')->nullable();     // array URL
            $table->string('makeup_type')->nullable();
            $table->string('collaboration')->nullable();
            $table->timestamps();

            $table->foreign('mua_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->index('mua_id');
        });
    }

    public function down(): void
    {
        // Hapus sesuai urutan FK (kebalikan dari up)
        Schema::dropIfExists('portfolios');
        Schema::dropIfExists('notifications');
        // drop check constraints (MySQL otomatis saat drop table, tapi aman untuk berjaga)
        if ($this->tableExists('reviews')) {
            try { DB::statement("ALTER TABLE reviews DROP CONSTRAINT chk_rating"); } catch (\Throwable $e) {}
        }
        Schema::dropIfExists('reviews');

        if ($this->tableExists('bookings')) {
            try { DB::statement("DROP INDEX uq_mua_dt_time_active ON bookings"); } catch (\Throwable $e) {}
        }
        Schema::dropIfExists('bookings');

        if ($this->tableExists('offerings')) {
            try { DB::statement("ALTER TABLE offerings DROP CONSTRAINT chk_collab_price"); } catch (\Throwable $e) {}
        }
        Schema::dropIfExists('offerings');

        Schema::dropIfExists('availability');
        Schema::dropIfExists('profiles');
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }
    
};