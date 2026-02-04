<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('financial_year_rules', function (Blueprint $table) {
            $table->id();

            // e.g. "2025-2026"
            $table->string('year_key', 9)->unique();

            // financial year boundaries
            $table->date('start_date');
            $table->date('end_date');

            // monthly due day (1..28/30/31). We'll clamp if month shorter.
            $table->unsignedTinyInteger('due_day')->default(25);

            // optional grace days before calling it overdue
            $table->unsignedTinyInteger('grace_days')->default(0);

            // mark current year
            $table->boolean('is_active')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_year_rules');
    }
};
