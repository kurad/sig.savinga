<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            // What does interest_rate mean?
            $table->string('interest_basis', 20)
                ->default('per_year') // or 'per_month' depending on your group default
                ->after('interest_rate'); // per_year | per_month | per_term

            // Only used when basis = per_term (e.g. 3 months)
            $table->unsignedTinyInteger('interest_term_months')
                ->nullable()
                ->after('interest_basis');

            // Snapshot of computed interest at issuance (optional but very useful)
            $table->decimal('interest_amount', 12, 2)
                ->default(0)
                ->after('interest_term_months');

            // Audit fields
            $table->unsignedBigInteger('rate_set_by')
                ->nullable()
                ->after('approved_by');

            $table->timestamp('rate_set_at')
                ->nullable()
                ->after('rate_set_by');

            $table->string('rate_notes')
                ->nullable()
                ->after('rate_set_at');

            $table->index(['interest_basis']);
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex(['interest_basis']);
            $table->dropColumn([
                'interest_basis',
                'interest_term_months',
                'interest_amount',
                'rate_set_by',
                'rate_set_at',
                'rate_notes',
            ]);
        });
    }
};
