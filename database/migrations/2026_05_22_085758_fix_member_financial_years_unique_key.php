use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_guarantors', function (Blueprint $table) {
            if (!Schema::hasColumn('loan_guarantors', 'participant_type')) {
                $table->enum('participant_type', ['user', 'beneficiary'])
                    ->default('user')
                    ->after('loan_id');
            }

            if (!Schema::hasColumn('loan_guarantors', 'beneficiary_id')) {
                $table->foreignId('beneficiary_id')
                    ->nullable()
                    ->after('guarantor_user_id')
                    ->constrained('beneficiaries')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('loan_guarantors', function (Blueprint $table) {
            if (Schema::hasColumn('loan_guarantors', 'beneficiary_id')) {
                $table->dropConstrainedForeignId('beneficiary_id');
            }

            if (Schema::hasColumn('loan_guarantors', 'participant_type')) {
                $table->dropColumn('participant_type');
            }
        });
    }
};