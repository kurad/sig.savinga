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
        Schema::create('contribution_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_ref')->nullable()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beneficiary_id')->nullable()->constrained('beneficiaries')->nullOnDelete();
            $table->foreignId('financial_year_rule_id')->constrained('financial_year_rules')->cascadeOnDelete();
            $table->decimal('total_amount', 12, 2);
            $table->date('paid_date');
            $table->string('start_period_key', 7);
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contribution_batches');
    }
};
