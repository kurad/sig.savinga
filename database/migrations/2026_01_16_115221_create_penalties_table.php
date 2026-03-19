<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beneficiary_id')->nullable()->constrained('beneficiaries')->nullOnDelete();
            $table->enum('source_type', ['contribution', 'loan','loan_installment','manual']);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('reason');

            $table->enum('status', ['unpaid', 'paid','waived'])->default('unpaid');

            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['user_id', 'source_type', 'source_id', 'reason'], 'uniq_penalty_source_reason');
                $table->index(['user_id']);
                $table->index(['beneficiary_id']);
                $table->index(['source_type', 'source_id']);
    
                

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('penalties');
    }
};
