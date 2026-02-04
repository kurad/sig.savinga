<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            if (!Schema::hasColumn('penalties', 'recorded_by')) {
                $table->unsignedBigInteger('recorded_by')->nullable()->after('reason');
                $table->index('recorded_by');
            }
        });

        // Ensure source_id_key exists (generated column for NULL-safe unique constraints)
        // If you already have it, this will skip.
        $columns = DB::select("SHOW COLUMNS FROM `penalties` LIKE 'source_id_key'");
        if (empty($columns)) {
            DB::statement("
                ALTER TABLE `penalties`
                ADD COLUMN `source_id_key` BIGINT(20) UNSIGNED
                GENERATED ALWAYS AS (IFNULL(`source_id`, 0)) STORED
            ");
        }

        // Add unique index to prevent duplicate auto penalties
        // Uses source_id_key so NULL source_id becomes 0 consistently.
        $indexes = DB::select("SHOW INDEX FROM `penalties` WHERE Key_name = 'penalties_unique_source'");
        if (empty($indexes)) {
            DB::statement("
                ALTER TABLE `penalties`
                ADD UNIQUE KEY `penalties_unique_source` (`user_id`, `source_type`, `source_id_key`, `reason`)
            ");
        }

        // Optional (recommended): foreign key to users for recorded_by and resolved_by
        // Uncomment if your DB has FK support and you want strict auditing integrity.
        /*
        Schema::table('penalties', function (Blueprint $table) {
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
        });
        */
    }

    public function down(): void
    {
        // Drop unique key if exists
        $indexes = DB::select("SHOW INDEX FROM `penalties` WHERE Key_name = 'penalties_unique_source'");
        if (!empty($indexes)) {
            DB::statement("ALTER TABLE `penalties` DROP INDEX `penalties_unique_source`");
        }

        // Drop recorded_by column (and index)
        if (Schema::hasColumn('penalties', 'recorded_by')) {
            Schema::table('penalties', function (Blueprint $table) {
                $table->dropIndex(['recorded_by']);
                $table->dropColumn('recorded_by');
            });
        }

        // If you added source_id_key via this migration, you may drop it.
        // If it existed before, dropping it would be wrong — so we only drop if you’re sure.
        // (Safer to leave it.)
        /*
        DB::statement("ALTER TABLE `penalties` DROP COLUMN `source_id_key`");
        */
    }
};
