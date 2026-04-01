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
        Schema::table('opening_balances', function (Blueprint $table) {

            // Remove old constraint (user_id + period)
            $table->dropUnique('opening_balances_user_id_as_of_period_unique');

            // Create new correct constraint
            $table->unique(
                ['user_id', 'beneficiary_id', 'as_of_period'],
                'opening_balances_owner_period_unique'
            );

            // Optional performance index
            $table->index('beneficiary_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('opening_balances', function (Blueprint $table) {

            // Remove new constraint
            $table->dropUnique('opening_balances_owner_period_unique');

            // Restore old constraint
            $table->unique(
                ['user_id', 'as_of_period'],
                'opening_balances_user_id_as_of_period_unique'
            );

            $table->dropIndex(['beneficiary_id']);
        });
    }
};