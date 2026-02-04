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
        Schema::create('system_rules', function (Blueprint $table) {
            $table->id();
            $table->enum('contribution_type', ['fixed', 'flexible']);
            $table->decimal('contribution_amount', 10, 2)->nullable();
            $table->enum('contribution_frequency', ['weekly', 'monthly']);

            $table->decimal('loan_interest_rate', 5, 2);
            $table->integer('loan_limit_multiplier');

            $table->decimal('late_contribution_penalty', 10, 2)->default(0);
            $table->decimal('missed_contribution_penalty', 10, 2)->default(0);
            $table->decimal('late_loan_penalty', 10, 2)->default(0);

            $table->enum('profit_share_method', ['equal', 'savings_ratio']);
            $table->unsignedSmallInteger('contribution_cycle_months')->default(12);
            $table->string('cycle_anchor_period', 7)->default('2024-01');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_rules');
    }
};
