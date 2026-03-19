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
        Schema::create('member_financial_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_year_rule_id')->constrained('financial_year_rules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beneficiary_id')->nullable()->constrained('beneficiaries')->nullOnDelete();
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('commitment_amount', 14, 2)->default(0);
            // computed at close time
            $table->decimal('closing_balance', 14, 2)->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->unique(['financial_year_rule_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_financial_years');
    }
};
