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
        Schema::table('member_financial_years', function (Blueprint $table) {
            $table->dropUnique('member_financial_years_financial_year_rule_id_user_id_unique');

            $table->unique(
                ['financial_year_rule_id', 'user_id', 'beneficiary_id'],
                'mfy_fy_user_beneficiary_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('member_financial_years', function (Blueprint $table) {
            $table->dropUnique('mfy_fy_user_beneficiary_unique');

            $table->unique(
                ['financial_year_rule_id', 'user_id'],
                'member_financial_years_financial_year_rule_id_user_id_unique'
            );
        });
    }
};
