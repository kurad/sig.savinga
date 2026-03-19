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
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // BASIC INFO
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone', 15)->unique();

            // OTP AUTH
            $table->string('otp_code_hash')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->timestamp('otp_last_sent_at')->nullable();

            // ROLE & STATUS
            $table->enum('role', ['admin', 'treasurer', 'member'])->default('member');
            $table->boolean('is_active')->default(true);
            $table->date('joined_at')->nullable();

            // MEMBER SOURCE
            $table->enum('source', ['manual', 'bulk_import'])->default('manual');

            // REGISTRATION FEE
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

            // 2FA
            $table->boolean('two_factor_enabled')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();

            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};