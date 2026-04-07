<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adjustments', function (Blueprint $table) {
            $table->id();

            $table->morphs('adjustable'); // adjustable_type, adjustable_id

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beneficiary_id')->nullable()->constrained()->nullOnDelete();

            $table->string('as_of_period', 7)->nullable();
            $table->decimal('amount', 14, 2); // signed delta
            $table->string('reason', 255);

            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'beneficiary_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjustments');
    }
};