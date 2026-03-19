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
        Schema::table('users', function (Blueprint $table) {
             $table->string('payment_mode')->default('self_pay')->index(); // payroll|self_pay
            $table->string('payroll_no')->after('id')->nullable()->index();            // HR identifier
            $table->date('payment_mode_effective_from')->nullable();      // optional
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['payment_mode', 'payroll_no', 'payment_mode_effective_from']);
        });
    }
};
