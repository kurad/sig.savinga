<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('loan_guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('guarantor_user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('pledged_amount', 10, 2);
            $table->enum('status', ['active', 'released', 'seized'])->default('active');
            $table->timestamps();

            $table->unique(['loan_id', 'guarantor_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_guarantors');
    }
};
