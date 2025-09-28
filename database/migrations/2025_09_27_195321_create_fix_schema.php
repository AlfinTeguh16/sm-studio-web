<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * USERS: ubah id → CHAR(36) (UUID)
         * Catatan: MODIFY akan otomatis menghilangkan atribut AUTO_INCREMENT.
         */
        if (Schema::hasTable('users')) {
            DB::statement("ALTER TABLE `users` MODIFY `id` CHAR(36) NOT NULL");
            // Pastikan tetap ada PK (biasanya tetap ada; ini hanya berjaga-jaga)
            try { DB::statement("ALTER TABLE `users` ADD PRIMARY KEY (`id`)"); } catch (\Throwable $e) {}
        }

        /**
         * SANCTUM: personal_access_tokens.tokenable_id → CHAR(36)
         * Index komposit mesti dijatuhkan lalu dibuat ulang agar MODIFY sukses di MySQL.
         */
        if (Schema::hasTable('personal_access_tokens')) {
            try {
                DB::statement("ALTER TABLE `personal_access_tokens` DROP INDEX `personal_access_tokens_tokenable_type_tokenable_id_index`");
            } catch (\Throwable $e) {
                // index mungkin belum ada / nama beda -> lanjutkan saja
            }

            DB::statement("ALTER TABLE `personal_access_tokens` MODIFY `tokenable_id` CHAR(36) NOT NULL");

            try {
                DB::statement("CREATE INDEX `personal_access_tokens_tokenable_type_tokenable_id_index` ON `personal_access_tokens` (`tokenable_type`, `tokenable_id`)");
            } catch (\Throwable $e) {
                // jika sudah ada, abaikan
            }
        }

        /**
         * (Opsional) SESSIONS: user_id → CHAR(36) NULL
         * Berguna kalau kamu pakai session-based auth (SPA) selain token Bearer.
         */
        if (Schema::hasTable('sessions') && Schema::hasColumn('sessions', 'user_id')) {
            try {
                DB::statement("ALTER TABLE `sessions` MODIFY `user_id` CHAR(36) NULL");
            } catch (\Throwable $e) {}
        }
    }

    public function down(): void
    {
        // Tidak direkomendasikan revert ke BIGINT karena data UUID sudah terisi.
        // Biarkan kosong (no-op). Bila benar-benar perlu, buat migrasi khusus
        // yang MENGOSONGKAN data dulu baru ubah tipe kembali.
    }

};
