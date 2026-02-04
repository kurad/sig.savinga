<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) opening_balances table
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->string('as_of_period', 7); // YYYY-MM
            $table->decimal('amount', 12, 2)->default(0);

            $table->string('note', 255)->nullable();

            // Link to the created transaction for audit trace
            $table->unsignedBigInteger('transaction_id')->nullable();

            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            $table->unique('user_id'); // one opening balance per member
            $table->index(['as_of_period']);
        });

        // 2) Extend transactions.type enum to include opening_balance
        // Your schema shows transactions.type enum currently (contribution, loan_disbursement, loan_repayment, penalty, profit, penalty_paid, penalty_waived) :contentReference[oaicite:2]{index=2}
        DB::statement("
            ALTER TABLE transactions
            MODIFY COLUMN type ENUM(
                'contribution',
                'loan_disbursement',
                'loan_repayment',
                'penalty',
                'profit',
                'penalty_paid',
                'penalty_waived',
                'opening_balance'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        // revert enum (remove opening_balance)
        DB::statement("
            ALTER TABLE transactions
            MODIFY COLUMN type ENUM(
                'contribution',
                'loan_disbursement',
                'loan_repayment',
                'penalty',
                'profit',
                'penalty_paid',
                'penalty_waived'
            ) NOT NULL
        ");

        Schema::dropIfExists('opening_balances');
    }
};
