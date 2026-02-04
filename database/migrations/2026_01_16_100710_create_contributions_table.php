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
        Schema::create('contributions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // YYYY-MM (e.g. 2024-03)
            $table->string('period_key', 7);

            $table->decimal('amount', 10, 2)->default(0);

            $table->date('expected_date');
            $table->date('paid_date')->nullable();

            $table->enum('status', ['paid', 'late', 'missed'])->default('paid');

            $table->decimal('penalty_amount', 10, 2)->default(0);

            $table->foreignId('recorded_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->timestamps();

            // ✅ Ensures 1 monthly envelope per member
            $table->unique(['user_id', 'period_key'], 'uniq_contribution_user_period');

            // Useful for reports
            $table->index(['period_key', 'status']);
            $table->index(['user_id', 'expected_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contributions');
    }
};
