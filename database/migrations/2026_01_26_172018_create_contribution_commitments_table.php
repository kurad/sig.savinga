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
        Schema::create('contribution_commitments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The member's committed monthly amount (>= min)
            $table->decimal('amount', 12, 2);

            // Cycle window (YYYY-MM)
            $table->string('cycle_start_period', 7); // e.g. 2024-01
            $table->string('cycle_end_period', 7);   // e.g. 2024-12

            // How long a cycle is (months) - store for audit
            $table->unsignedSmallInteger('cycle_months');

            $table->enum('status', ['active', 'expired'])->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['cycle_start_period', 'cycle_end_period'], 'cc_cycle_period_idx');

            // ✅ prevent duplicate cycle rows per member
            $table->unique(['user_id', 'cycle_start_period', 'cycle_end_period'], 'uniq_commitment_user_cycle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contribution_commitments');
    }
};
