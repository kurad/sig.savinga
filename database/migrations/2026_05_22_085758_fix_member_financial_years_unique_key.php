<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('loan_guarantors', 'participant_type')) {
            Schema::table('loan_guarantors', function (Blueprint $table) {
                $table->enum('participant_type', ['user', 'beneficiary'])
                    ->default('user')
                    ->after('loan_id');
            });
        }

        if (!Schema::hasColumn('loan_guarantors', 'beneficiary_id')) {
            Schema::table('loan_guarantors', function (Blueprint $table) {
                $table->foreignId('beneficiary_id')
                    ->nullable()
                    ->after('guarantor_user_id')
                    ->constrained('beneficiaries')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('loan_guarantors', 'beneficiary_id')) {
            Schema::table('loan_guarantors', function (Blueprint $table) {
                $table->dropForeign(['beneficiary_id']);
                $table->dropColumn('beneficiary_id');
            });
        }

        if (Schema::hasColumn('loan_guarantors', 'participant_type')) {
            Schema::table('loan_guarantors', function (Blueprint $table) {
                $table->dropColumn('participant_type');
            });
        }
    }
};