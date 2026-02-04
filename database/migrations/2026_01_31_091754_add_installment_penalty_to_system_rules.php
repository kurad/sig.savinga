<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('system_rules', function (Blueprint $table) {
            // percent_of_installment OR fixed
            $table->string('loan_installment_penalty_type')->default('percent_of_installment');
            $table->decimal('loan_installment_penalty_value', 10, 2)->default(0); // e.g. 2.5 = 2.5%
        });
    }

    public function down(): void
    {
        Schema::table('system_rules', function (Blueprint $table) {
            $table->dropColumn([
                'loan_installment_penalty_type',
                'loan_installment_penalty_value',
            ]);
        });
    }
};
