<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('installment_no'); // 1..N
            $table->date('due_date');

            $table->decimal('amount_due', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);

            // pending | partial | paid
            $table->string('status')->default('pending');

            $table->date('paid_date')->nullable(); // date the installment got fully paid

            // prevent double penalty application
            $table->timestamp('penalty_applied_at')->nullable();
            $table->unsignedBigInteger('penalty_id')->nullable(); // optional link to penalties table if you store it

            $table->timestamps();

            $table->unique(['loan_id', 'installment_no']);
            $table->index(['loan_id', 'status']);
            $table->index(['loan_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_installments');
    }
};
