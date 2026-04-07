<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\User;
use App\Services\LoanMigrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LoanMigrationController extends Controller
{
    public function __construct(
        protected LoanMigrationService $loanMigrationService
    ) {}

    /**
     * Migrate an existing loan into the new system.
     */
    public function store(Request $request, Loan $loan)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json([
                'message' => 'Forbidden.'
            ], 403);
        }

        $validated = $request->validate([
            'migration_date' => ['required', 'date'],
            'original_principal' => ['required', 'numeric', 'min:0.01'],
            'original_total_payable' => ['nullable', 'numeric', 'min:0'],
            'principal_paid_before_migration' => ['nullable', 'numeric', 'min:0'],
            'interest_paid_before_migration' => ['nullable', 'numeric', 'min:0'],
            'outstanding_principal' => ['required', 'numeric', 'min:0'],
            'outstanding_interest' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);

        try {
            $snapshot = $this->loanMigrationService->migrateLoan(
                loan: $loan,
                data: $validated,
                createdBy: (int) $user->id
            );

            return response()->json([
                'message' => 'Loan migrated successfully.',
                'data' => $snapshot,
            ], 201);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'loan' => [$e->getMessage()],
            ]);
        }
    }

    /**
     * Create a migrated loan directly from a member.
     */
    public function storeFromMember(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json([
                'message' => 'Forbidden.'
            ], 403);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],

            'migration_date' => ['required', 'date'],
            'original_principal' => ['required', 'numeric', 'min:0.01'],
            'original_total_payable' => ['nullable', 'numeric', 'min:0'],
            'principal_paid_before_migration' => ['nullable', 'numeric', 'min:0'],
            'interest_paid_before_migration' => ['nullable', 'numeric', 'min:0'],
            'outstanding_principal' => ['required', 'numeric', 'min:0'],
            'outstanding_interest' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],

            'issued_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'duration_months' => ['nullable', 'integer', 'min:1'],
            'repayment_mode' => ['nullable', 'in:once,installment'],

            'guarantors' => ['nullable', 'array'],
            'guarantors.*.participant_type' => ['required', 'in:user,beneficiary'],
            'guarantors.*.guarantor_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'guarantors.*.beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'guarantors.*.pledged_amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $result = DB::transaction(function () use ($validated, $user) {
                $member = User::findOrFail($validated['user_id']);

                $originalPrincipal = round((float) $validated['original_principal'], 2);

                $originalTotalPayable = array_key_exists('original_total_payable', $validated)
                    && $validated['original_total_payable'] !== null
                    ? round((float) $validated['original_total_payable'], 2)
                    : $originalPrincipal;

                $principalPaidBeforeMigration = round(
                    (float) ($validated['principal_paid_before_migration'] ?? 0),
                    2
                );

                $interestPaidBeforeMigration = round(
                    (float) ($validated['interest_paid_before_migration'] ?? 0),
                    2
                );

                $outstandingPrincipal = round(
                    (float) ($validated['outstanding_principal'] ?? 0),
                    2
                );

                $outstandingInterest = round(
                    (float) ($validated['outstanding_interest'] ?? 0),
                    2
                );

                $repaymentMode = $validated['repayment_mode'] ?? 'once';
                $durationMonths = (int) ($validated['duration_months'] ?? 1);

                if ($repaymentMode === 'once') {
                    $durationMonths = 1;
                }

                if ($repaymentMode === 'installment' && $durationMonths < 1) {
                    throw new InvalidArgumentException(
                        'duration_months must be at least 1 for installment loans.'
                    );
                }

                if ($outstandingInterest > 0) {
                    throw new InvalidArgumentException(
                        'Outstanding interest must be zero because interest is deducted upfront before disbursement.'
                    );
                }

                if ($principalPaidBeforeMigration > $originalPrincipal) {
                    throw new InvalidArgumentException(
                        'Principal paid before migration cannot exceed original principal.'
                    );
                }

                if (round($principalPaidBeforeMigration + $outstandingPrincipal, 2) > $originalPrincipal) {
                    throw new InvalidArgumentException(
                        'Principal paid before migration plus outstanding principal cannot exceed original principal.'
                    );
                }

                $issuedDate = $validated['issued_date'] ?? $validated['migration_date'];
                $dueDate = $validated['due_date'] ?? $validated['migration_date'];

                $loan = Loan::create([
                    'user_id' => $member->id,
                    'beneficiary_id' => $validated['beneficiary_id'] ?? null,
                    'principal' => $originalPrincipal,
                    'interest_rate' => 0,
                    'total_payable' => $outstandingPrincipal,
                    'duration_months' => $durationMonths,
                    'issued_date' => $issuedDate,
                    'due_date' => $dueDate,
                    'status' => $outstandingPrincipal > 0 ? 'active' : 'completed',
                    'repayment_mode' => $repaymentMode,
                    'monthly_installment' => $repaymentMode === 'installment'
                        ? round($outstandingPrincipal / max(1, $durationMonths), 2)
                        : null,
                    'approved_by' => $user->id,
                    'is_migrated' => false,
                ]);

                if (!empty($validated['guarantors'])) {
                    $seen = [];

                    foreach ($validated['guarantors'] as $g) {
                        $participantType = $g['participant_type'];
                        $pledgedAmount = round((float) $g['pledged_amount'], 2);

                        if ($participantType === 'user') {
                            $guarantorUserId = (int) ($g['guarantor_user_id'] ?? 0);

                            if (!$guarantorUserId) {
                                throw new InvalidArgumentException('Guarantor member is required.');
                            }

                            if ($guarantorUserId === (int) $member->id) {
                                throw new InvalidArgumentException('Borrower cannot guarantee their own loan.');
                            }

                            $key = 'user:' . $guarantorUserId;
                            if (in_array($key, $seen, true)) {
                                throw new InvalidArgumentException('Duplicate guarantors are not allowed.');
                            }

                            $seen[] = $key;

                            $loan->guarantors()->create([
                                'participant_type' => 'user',
                                'guarantor_user_id' => $guarantorUserId,
                                'beneficiary_id' => null,
                                'pledged_amount' => $pledgedAmount,
                                'status' => 'active',
                            ]);
                        } else {
                            $beneficiaryId = (int) ($g['beneficiary_id'] ?? 0);

                            if (!$beneficiaryId) {
                                throw new InvalidArgumentException('Guarantor beneficiary is required.');
                            }

                            if ((int) ($validated['beneficiary_id'] ?? 0) === $beneficiaryId) {
                                throw new InvalidArgumentException('Borrower beneficiary cannot guarantee their own loan.');
                            }

                            $key = 'beneficiary:' . $beneficiaryId;
                            if (in_array($key, $seen, true)) {
                                throw new InvalidArgumentException('Duplicate guarantors are not allowed.');
                            }

                            $seen[] = $key;

                            $loan->guarantors()->create([
                                'participant_type' => 'beneficiary',
                                'guarantor_user_id' => null,
                                'beneficiary_id' => $beneficiaryId,
                                'pledged_amount' => $pledgedAmount,
                                'status' => 'active',
                            ]);
                        }
                    }
                }

                $snapshot = $this->loanMigrationService->migrateLoan(
                    loan: $loan,
                    data: [
                        'migration_date' => $validated['migration_date'],
                        'original_principal' => $originalPrincipal,
                        'original_total_payable' => $originalTotalPayable,
                        'principal_paid_before_migration' => $principalPaidBeforeMigration,
                        'interest_paid_before_migration' => $interestPaidBeforeMigration,
                        'outstanding_principal' => $outstandingPrincipal,
                        'outstanding_interest' => $outstandingInterest,
                        'note' => $validated['note'] ?? null,
                    ],
                    createdBy: (int) $user->id
                );

                $summary = $this->loanMigrationService->migrationSummary($loan);

                return [
                    'loan' => $loan->fresh([
                        'user',
                        'beneficiary',
                        'migrationSnapshot',
                        'guarantors.guarantor',
                        'guarantors.beneficiary',
                    ]),
                    'snapshot' => $snapshot,
                    'summary' => $summary,
                ];
            });

            return response()->json([
                'message' => 'Loan migrated successfully.',
                'data' => $result,
            ], 201);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'migration' => [$e->getMessage()],
            ]);
        }
    }
    /**
     * Get current outstanding balances for a migrated loan.
     */
    public function outstanding(Request $request, Loan $loan)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json([
                'message' => 'Forbidden.'
            ], 403);
        }

        try {
            $data = $this->loanMigrationService->outstandingAfterMigration($loan);

            return response()->json([
                'data' => $data,
            ]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'loan' => [$e->getMessage()],
            ]);
        }
    }

    /**
     * Get full migration summary for a migrated loan.
     */
    public function summary(Request $request, Loan $loan)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json([
                'message' => 'Forbidden.'
            ], 403);
        }

        try {
            $data = $this->loanMigrationService->migrationSummary($loan);

            return response()->json([
                'data' => $data,
            ]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'loan' => [$e->getMessage()],
            ]);
        }
    }
}
