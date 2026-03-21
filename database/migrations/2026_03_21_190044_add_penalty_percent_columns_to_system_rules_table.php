<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_rules', function (Blueprint $table) {
            $table->decimal('late_contribution_penalty_percent', 8, 2)
                ->default(0)
                ->after('late_contribution_penalty');

            $table->decimal('missed_contribution_penalty_percent', 8, 2)
                ->default(0)
                ->after('missed_contribution_penalty');

            $table->decimal('late_loan_penalty_percent', 8, 2)
                ->default(0)
                ->after('late_loan_penalty');
        });
    }

    public function down(): void
    {
        Schema::table('system_rules', function (Blueprint $table) {
            $table->dropColumn([
                'late_contribution_penalty_percent',
                'missed_contribution_penalty_percent',
                'late_loan_penalty_percent',
            ]);
        });
    }
};