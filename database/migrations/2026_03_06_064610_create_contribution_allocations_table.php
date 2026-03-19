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
        Schema::create('contribution_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contribution_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contribution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            $table->string('period_key', 7);
            $table->decimal('allocated_amount', 12, 2);

            $table->decimal('before_amount', 12, 2)->default(0);
            $table->decimal('after_amount', 12, 2)->default(0);

            $table->date('before_paid_date')->nullable();
            $table->date('after_paid_date')->nullable();

            $table->string('before_status')->nullable();
            $table->string('after_status')->nullable();

            $table->decimal('before_penalty_amount', 12, 2)->default(0);
            $table->decimal('after_penalty_amount', 12, 2)->default(0);

            $table->date('before_expected_date')->nullable();
            $table->date('after_expected_date')->nullable();

            $table->unsignedBigInteger('before_recorded_by')->nullable();
            $table->unsignedBigInteger('after_recorded_by')->nullable();

            $table->boolean('created_new')->default(false);
            $table->boolean('penalty_applied_now')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contribution_allocations');
    }
};
