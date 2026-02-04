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
        Schema::table('system_rules', function (Blueprint $table) {
            $table->unsignedTinyInteger('contribution_due_day')->default(25);
            $table->decimal('contribution_min_amount', 12, 2)->default(0);

            $table->boolean('allow_overpay')->default(true);
            $table->boolean('allow_underpay')->default(true);

            // optional: how to handle underpaying minimum
            $table->enum('underpay_policy', ['none','warn','penalize','carry_forward'])->default('warn');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_rules', function (Blueprint $table) {
            $table->dropColumn([
                'contribution_due_day',
                'contribution_min_amount',
                'allow_overpay',
                'allow_underpay',
                'underpay_policy',
            ]);
        });
    }
};
