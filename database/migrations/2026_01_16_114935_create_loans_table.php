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
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beneficiary_id')->nullable()->constrained('beneficiaries')->nullOnDelete();
            $table->decimal('principal', 10, 2);
            $table->decimal('interest_rate', 5, 2);
            $table->decimal('total_payable', 10, 2);
            $table->integer('duration_months');
            $table->date('issued_date')->nullable();
            $table->date('due_date')->nullable();
            $table->enum('status', [
                'pending',
                'approved',
                'active',
                'completed',
                'defaulted'
            ]);
            $table->enum('repayment_mode', ['once', 'installment'])->default('installment');
            $table->decimal('monthly_installment', 10, 2)->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
