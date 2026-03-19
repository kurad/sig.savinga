<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) opening_balances table
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('beneficiary_id')->nullable();
            $table->string('as_of_period', 7); // YYYY-MM
            $table->decimal('amount', 12, 2)->default(0);

            $table->string('note', 255)->nullable();

            // Link to the created transaction for audit trace
            $table->unsignedBigInteger('transaction_id')->nullable();

            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'as_of_period']); // one opening balance per member
            $table->index(['as_of_period']);
            $table->index(['user_id', 'beneficiary_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};
