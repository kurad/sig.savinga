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
        Schema::table('financial_year_rules', function (Blueprint $table) {
            $table->integer('due_month_offset')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('financial_year_rules', function (Blueprint $table) {
            $table->dropColumn('due_month_offset');
        });
    }
};
