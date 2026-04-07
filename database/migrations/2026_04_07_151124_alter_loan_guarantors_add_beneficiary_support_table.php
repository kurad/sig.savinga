<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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

        Schema::table('loan_guarantors', function (Blueprint $table) {
            $table->dropUnique(['loan_id', 'guarantor_user_id']);
        });

        DB::statement("
            UPDATE loan_guarantors
            SET participant_type = 'user'
            WHERE participant_type IS NULL OR participant_type = ''
        ");
    }

    public function down(): void
    {
        Schema::table('loan_guarantors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('beneficiary_id');
            $table->dropColumn('participant_type');
        });

        Schema::table('loan_guarantors', function (Blueprint $table) {
            $table->unique(['loan_id', 'guarantor_user_id']);
        });
    }
};