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
            // Loan limit policy
            $table->enum('loan_limit_type', ['multiple', 'equal', 'fixed'])->default('multiple');
            $table->decimal('loan_limit_value', 10, 2)->default(3); // 3x by default

            // Optional eligibility constraints
            $table->unsignedInteger('min_contribution_months')->default(0);
            $table->boolean('allow_multiple_active_loans')->default(false);

            // Repayment policy defaults (used at disburse if UI doesn't specify)
            $table->enum('loan_default_repayment_mode', ['once', 'installment'])->default('once');

            // How to evaluate contributions for eligibility
            $table->enum('loan_eligibility_basis', ['total_contributions', 'net_contributions'])->default('total_contributions');
            // net_contributions = total contributions - (optional) withdrawals if you ever add withdrawals
        
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_rules', function (Blueprint $table) {
            $table->dropColumn([
                'loan_limit_type',
                'loan_limit_value',
                'min_contribution_months',
                'allow_multiple_active_loans',
                'loan_default_repayment_mode',
                'loan_eligibility_basis',
            ]);
        });
    }
};
