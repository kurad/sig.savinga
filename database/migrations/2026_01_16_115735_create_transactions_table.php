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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('beneficiary_id')->nullable()->constrained('beneficiaries')->nullOnDelete();
            $table->enum('type', [
                'opening_balance',
                'opening_loan',
                'contribution',
                'loan_disbursement',
                'loan_repayment',
                'penalty',
                'profit',
                'penalty_paid',
                'penalty_waived',
                'loan_interest_deducted',
                'expense',
                'contribution_reversal',
                'penalty_reversal',
                'investment',
                'investment_sale',
                'registration_fee',
                
            ]);
            $table->decimal('debit', 10, 2)->default(0);
            $table->decimal('credit', 10, 2)->default(0);
            $table->string('reference');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by')->constrained('users');

            $table->index(['source_type', 'source_id']);
            $table->index(['user_id', 'type', 'created_at', 'beneficiary_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
