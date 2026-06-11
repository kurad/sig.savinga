<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\User;
use App\Services\LoanMigrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use App\Imports\LoanMigrationImport;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LoanMigrationController extends Controller
{
    public function __construct(
        protected LoanMigrationService $loanMigrationService
    ) {}

    public function store(Request $request, Loan $loan)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'issued_date' => ['required', 'date'],
            'due_date' => ['required', 'date'],
            'migration_date' => ['nullable', 'date'],

            'original_principal' => ['required', 'numeric', 'min:0.01'],
            'number_of_installments' => ['required', 'integer', 'min:1'],
            'paid_installments' => ['required', 'integer', 'min:0'],
            'outstanding_principal' => ['required', 'numeric', 'min:0'],
            'principal_paid_before_migration' => ['nullable', 'numeric', 'min:0'],

            'note' => ['nullable', 'string'],
        ]);

        try {
            $snapshot = $this->loanMigrationService->migrateLoan(
                loan: $loan,
                data: [
                    'issued_date' => $validated['issued_date'],
                    'due_date' => $validated['due_date'],
                    'migration_date' => $validated['migration_date'] ?? $validated['issued_date'],

                    'original_principal' => round((float) $validated['original_principal'], 2),
                    'number_of_installments' => (int) $validated['number_of_installments'],
                    'paid_installments' => (int) $validated['paid_installments'],
                    'outstanding_principal' => round((float) $validated['outstanding_principal'], 2),
                    'principal_paid_before_migration' => round(
                        (float) ($validated['principal_paid_before_migration'] ?? 0),
                        2
                    ),

                    'note' => $validated['note'] ?? null,
                ],
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

    public function storeFromMember(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],

            'issued_date' => ['required', 'date'],
            'due_date' => ['required', 'date'],
            'migration_date' => ['nullable', 'date'],

            'original_principal' => ['required', 'numeric', 'min:0.01'],
            'number_of_installments' => ['required', 'integer', 'min:1'],
            'paid_installments' => ['required', 'integer', 'min:0'],
            'outstanding_principal' => ['required', 'numeric', 'min:0'],
            'principal_paid_before_migration' => ['nullable', 'numeric', 'min:0'],

            'duration_months' => ['nullable', 'integer', 'min:1'],
            'repayment_mode' => ['nullable', 'in:once,installment'],
            'note' => ['nullable', 'string'],

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
                $numberOfInstallments = (int) $validated['number_of_installments'];
                $paidInstallments = (int) $validated['paid_installments'];
                $outstandingPrincipal = round((float) $validated['outstanding_principal'], 2);

                if ($paidInstallments > $numberOfInstallments) {
                    throw new InvalidArgumentException(
                        'paid_installments cannot exceed number_of_installments.'
                    );
                }

                $principalPaidBeforeMigration = round(
                    (float) (
                        $validated['principal_paid_before_migration']
                        ?? ($originalPrincipal - $outstandingPrincipal)
                    ),
                    2
                );

                if ($principalPaidBeforeMigration < 0) {
                    throw new InvalidArgumentException(
                        'Principal paid before migration cannot be negative.'
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

                $remainingInstallments = max(0, $numberOfInstallments - $paidInstallments);
                $durationMonths = (int) ($validated['duration_months'] ?? max(1, $remainingInstallments));

                if ($durationMonths < 1) {
                    $durationMonths = 1;
                }

                $monthlyInstallment = $remainingInstallments > 0
                    ? round($outstandingPrincipal / $remainingInstallments, 2)
                    : null;

                $loan = Loan::create([
                    'user_id' => $member->id,
                    'beneficiary_id' => $validated['beneficiary_id'] ?? null,

                    'principal' => $originalPrincipal,
                    'interest_rate' => 0,
                    'total_payable' => $outstandingPrincipal,

                    'duration_months' => $durationMonths,
                    'issued_date' => $validated['issued_date'],
                    'due_date' => $validated['due_date'],

                    'status' => $outstandingPrincipal > 0 ? 'active' : 'completed',
                    'repayment_mode' => 'installment',
                    'monthly_installment' => $monthlyInstallment,

                    'approved_by' => $user->id,
                    'is_migrated' => false,
                ]);

                $this->createGuarantors(
                    loan: $loan,
                    member: $member,
                    validated: $validated
                );

                $snapshot = $this->loanMigrationService->migrateLoan(
                    loan: $loan,
                    data: [
                        'issued_date' => $validated['issued_date'],
                        'due_date' => $validated['due_date'],
                        'migration_date' => $validated['migration_date'] ?? $validated['issued_date'],

                        'original_principal' => $originalPrincipal,
                        'number_of_installments' => $numberOfInstallments,
                        'paid_installments' => $paidInstallments,

                        'principal_paid_before_migration' => $principalPaidBeforeMigration,
                        'outstanding_principal' => $outstandingPrincipal,

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
                        'installments',
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

    private function createGuarantors(Loan $loan, User $member, array $validated): void
    {
        if (empty($validated['guarantors'])) {
            return;
        }

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

                continue;
            }

            $beneficiaryId = (int) ($g['beneficiary_id'] ?? 0);

            if (!$beneficiaryId) {
                throw new InvalidArgumentException('Guarantor beneficiary is required.');
            }

            if ((int) ($validated['beneficiary_id'] ?? 0) === $beneficiaryId) {
                throw new InvalidArgumentException(
                    'Borrower beneficiary cannot guarantee their own loan.'
                );
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

    public function outstanding(Request $request, Loan $loan)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
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

    public function summary(Request $request, Loan $loan)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
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
    public function template(): StreamedResponse
    {
        $headers = [
            'member_id',
            'member_name',
            'phone',
            'email',

            'original_principal',
            'number_of_installments',
            'paid_installments',
            'outstanding_principal',

            'issued_date',
            'due_date',
            'migration_date',

            'note',
        ];

        $members = User::query()
            ->select('id', 'name', 'phone', 'email')
            ->orderBy('name')
            ->get();

        return response()->streamDownload(function () use ($headers, $members) {
            $out = fopen('php://output', 'w');

            fputcsv($out, $headers);

            foreach ($members as $member) {
                fputcsv($out, [
                    $member->id,
                    $member->name,
                    $member->phone,
                    $member->email,

                    '',
                    '',
                    '',
                    '',

                    '',
                    '',
                    '',

                    '',
                ]);
            }

            fclose($out);
        }, 'loan_migration_template.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
    public function import(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $import = app(LoanMigrationImport::class);
        $import->setCreatedBy((int) $user->id);

        Excel::import($import, $request->file('file'));

        return response()->json([
            'message' => 'Loan migration import completed.',
            'summary' => $import->summary(),
            'errors' => $import->errors(),
        ]);
    }
}
