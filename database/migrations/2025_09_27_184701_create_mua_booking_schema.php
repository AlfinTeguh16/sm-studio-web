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
            $table->json('add_ons')->nullable();           // array string/obj
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
         * BOOKINGS — pemesanan (sekaligus invoice untuk manual payment)
         */
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            // Relasi utama
            $table->uuid('customer_id');
            $table->uuid('mua_id');
            $table->foreignId('offering_id')->nullable()->constrained('offerings')->nullOnDelete();

            // Waktu & tipe layanan
            $table->date('booking_date');
            $table->string('booking_time', 5); // 'HH:MM'
            $table->enum('service_type', ['home_service','studio']);
            
            $table->integer('person')->default(1); // jumlah orang (>=1)

            // Detail lokasi & catatan
            $table->string('location_address')->nullable(); // wajib di FE jika service_type=home_service
            $table->text('notes')->nullable();

            // ======== INVOICE META ========
            $table->string('invoice_number')->unique()->nullable(); // auto diisi pada create
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();

            // ======== HARGA & TOTAL ========
            $table->decimal('amount', 12, 2)->nullable(); // base price (dari offering)
            $table->json('selected_add_ons')->nullable(); // [{name, price}, ...]

            // kolom baru untuk perhitungan yang benar
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->nullable();

            // (opsional) tetap simpan kolom lama jika ingin kompatibel — biarkan nullable
            $table->string('tax')->nullable();   // misal persentase sebagai string, tidak dipakai kalkulasi baru
            $table->string('total')->nullable(); // tidak dipakai kalkulasi baru

            // ======== STATUS WORKFLOW ========
            // status lama dipertahankan; alur baru gunakan job_status
            $table->enum('status', ['pending','confirmed','rejected','cancelled','completed'])->default('pending');
            $table->enum('job_status', ['pending','confirmed','in_progress','completed','cancelled'])->default('pending');

            // ======== PEMBAYARAN MANUAL ========
            $table->string('payment_method')->nullable(); // cash | transfer | qris | card | other
            $table->enum('payment_status', ['unpaid','partial','paid','refunded','void'])->default('unpaid');
            $table->timestamp('paid_at')->nullable();

            // Lainnya
            $table->boolean('use_collaboration')->default(false);
            $table->timestamps();

            // FK
            $table->foreign('customer_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->foreign('mua_id')->references('id')->on('profiles')->onDelete('cascade');

            // Index
            $table->index(['mua_id','booking_date']);
            $table->index(['customer_id']);
            $table->index(['payment_status']);
            $table->index(['job_status']);
        });

        // Cegah double-booking aktif berbasis job_status (pending|confirmed|in_progress)
        DB::statement("ALTER TABLE bookings 
                       ADD COLUMN active TINYINT 
                       AS (IF(job_status IN ('pending','confirmed','in_progress'), 1, 0)) STORED");
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

        Schema::create('portfolios', function (Blueprint $table) {
            $table->id();
            $table->uuid('mua_id');
            $table->string('name');
            $table->json('photos')->nullable();     // array URL, contoh: ["https://...","https://..."]
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
