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
        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
             $table->decimal('amount', 14, 2);
            $table->string('category', 100)->nullable(); // donations, fees, etc
            $table->string('description', 255)->nullable();
            $table->date('income_date');
            $table->unsignedBigInteger('recorded_by');
            $table->timestamps();

            $table->index(['income_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incomes');
    }
};
