<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficiaries', function (Blueprint $table) {
            $table->id();

            // Parent / guardian who can log in and monitor records
            $table->foreignId('guardian_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('name');
            $table->date('date_of_birth')->nullable();
            $table->string('relationship')->nullable(); // son, daughter, niece...
            $table->boolean('is_active')->default(true);
            $table->date('joined_at')->nullable();

            // Registration fee fields if group charges per child
            $table->boolean('registration_fee_required')->default(true);
            $table->decimal('registration_fee_amount', 10, 2)->default(10000);
            $table->enum('registration_fee_status', [
                'pending',
                'paid',
                'waived',
                'not_applicable',
            ])->default('pending');
            $table->timestamp('registration_paid_at')->nullable();
            $table->foreignId('registration_recorded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('registration_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficiaries');
    }
};