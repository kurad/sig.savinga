<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('loan_guarantors', function (Blueprint $table) {
            $table->enum('participant_type', ['user', 'beneficiary'])
                ->default('user')
                ->after('loan_id');

            $table->foreignId('beneficiary_id')
                ->nullable()
                ->after('guarantor_user_id')
                ->constrained('beneficiaries')
                ->nullOnDelete();
        });

        // Add standalone indexes first so MySQL no longer depends on the old composite unique
        Schema::table('loan_guarantors', function (Blueprint $table) {
            $table->index('loan_id', 'loan_guarantors_loan_id_index');
            $table->index('guarantor_user_id', 'loan_guarantors_guarantor_user_id_index');
        });

        // Now drop the old composite unique
        Schema::table('loan_guarantors', function (Blueprint $table) {
            $table->dropUnique('loan_guarantors_loan_id_guarantor_user_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('loan_guarantors', function (Blueprint $table) {
            $table->unique(['loan_id', 'guarantor_user_id'], 'loan_guarantors_loan_id_guarantor_user_id_unique');
        });

        Schema::table('loan_guarantors', function (Blueprint $table) {
            $table->dropIndex('loan_guarantors_loan_id_index');
            $table->dropIndex('loan_guarantors_guarantor_user_id_index');
        });

        Schema::table('loan_guarantors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('beneficiary_id');
            $table->dropColumn('participant_type');
        });
    }
};