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
            $table->boolean('allow_loan_top_up')->default(true);
            $table->unsignedInteger('min_installments_before_top_up')->default(3);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_rules', function (Blueprint $table) {
            $table->dropColumn(['allow_loan_top_up', 'min_installments_before_top_up']);
        });
    }
};
