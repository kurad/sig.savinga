<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('penalties', 'source_id')) {
            DB::statement("ALTER TABLE `penalties` MODIFY `source_id` BIGINT UNSIGNED NULL");
        }

        if (Schema::hasColumn('penalties', 'source_type')) {
            DB::statement("ALTER TABLE `penalties` MODIFY `source_type` ENUM('contribution','loan','loan_installment','manual') NOT NULL");
        }

        if (Schema::hasColumn('penalties', 'status')) {
            DB::statement("ALTER TABLE `penalties` MODIFY `status` ENUM('unpaid','paid','waived') NOT NULL DEFAULT 'unpaid'");
        }

        if (!Schema::hasColumn('penalties', 'source_id_key')) {
            DB::statement("
                ALTER TABLE `penalties`
                ADD COLUMN `source_id_key` BIGINT UNSIGNED
                GENERATED ALWAYS AS (IFNULL(`source_id`, 0)) STORED
            ");
        }

        // Add unique index only if it doesn't exist
        $exists = DB::selectOne("
            SELECT COUNT(1) AS c
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'penalties'
              AND INDEX_NAME = 'uniq_penalty_source_reason'
        ");

        if ((int)($exists->c ?? 0) === 0) {
            DB::statement("
                ALTER TABLE `penalties`
                ADD UNIQUE KEY `uniq_penalty_source_reason`
                (`user_id`, `source_type`, `source_id_key`, `reason`)
            ");
        }
        
    }

    public function down(): void
    {
        // drop unique index if exists
        $exists = DB::selectOne("
            SELECT COUNT(1) AS c
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'penalties'
              AND INDEX_NAME = 'uniq_penalty_source_reason'
        ");

        if ((int)($exists->c ?? 0) > 0) {
            DB::statement("ALTER TABLE `penalties` DROP INDEX `uniq_penalty_source_reason`");
        }

        if (Schema::hasColumn('penalties', 'source_id_key')) {
            DB::statement("ALTER TABLE `penalties` DROP COLUMN `source_id_key`");
        }

        if (Schema::hasColumn('penalties', 'status')) {
            DB::statement("ALTER TABLE `penalties` MODIFY `status` ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid'");
        }

        if (Schema::hasColumn('penalties', 'source_type')) {
            DB::statement("ALTER TABLE `penalties` MODIFY `source_type` ENUM('contribution','loan') NOT NULL");
        }

        if (Schema::hasColumn('penalties', 'source_id')) {
            DB::statement("ALTER TABLE `penalties` MODIFY `source_id` BIGINT UNSIGNED NOT NULL");
        }
    
       }
};
