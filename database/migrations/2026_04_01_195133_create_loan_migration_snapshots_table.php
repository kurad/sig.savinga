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
        Schema::create('loan_migration_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('loan_id')
                ->constrained('loans')
                ->cascadeOnDelete();

            $table->decimal('original_principal', 10, 2);
            $table->decimal('original_total_payable', 10, 2)->nullable();

            $table->decimal('principal_paid_before_migration', 10, 2)->default(0);
            $table->decimal('interest_paid_before_migration', 10, 2)->default(0);

            $table->decimal('outstanding_principal', 10, 2)->default(0);
            $table->decimal('outstanding_interest', 10, 2)->default(0);

            $table->date('migration_date');
            $table->text('note')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique('loan_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_migration_snapshots');
    }
};
