<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
             // 1) Make source_id nullable (required for manual penalties)
            $table->unsignedBigInteger('source_id')->nullable()->change();

            // 2) Expand enums to match service usage
            $table->enum('source_type', ['contribution', 'loan', 'manual'])->change();
            $table->enum('status', ['unpaid', 'paid', 'waived'])->default('unpaid')->change();

            // 3) Unique constraint to prevent duplicate penalties for same source/reason
            $table->unique(['user_id', 'source_type', 'source_id', 'reason'], 'uniq_penalty_source_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropUnique('uniq_penalty_source_reason');
            // rollback enums (optional, but shown)
            $table->enum('status', ['unpaid', 'paid'])->default('unpaid')->change();
            $table->enum('source_type', ['contribution', 'loan'])->change();
            $table->unsignedBigInteger('source_id')->nullable(false)->change();
        });
    }
};
