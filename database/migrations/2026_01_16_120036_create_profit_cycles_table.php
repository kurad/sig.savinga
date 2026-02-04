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
        Schema::create('profit_cycles', function (Blueprint $table) {
            $table->id();
            $table->date('start_date');
            $table->date('end_date');

            // computed totals
            $table->decimal('interest_income', 14, 2)->default(0);
            $table->decimal('penalty_income', 14, 2)->default(0);
            $table->decimal('other_income', 14, 2)->default(0);
            $table->decimal('expenses', 14, 2)->default(0);
            $table->decimal('net_profit', 14, 2)->default(0);

            $table->string('status')->default('open'); // open|closed
            $table->unsignedBigInteger('opened_by');
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profit_cycles');
    }
};
