<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanMigrationSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LoanMigrationService
{
    private string $tz = 'Africa/Kigali';

    public function __construct(
        protected TransactionService $transactionService
    ) {}

    public function migrateLoan(Loan $loan, array $data, int $createdBy): LoanMigrationSnapshot
    {
        if ($loan->is_migrated) {
            throw new InvalidArgumentException('This loan has already been migrated.');
        }

        if ($loan->migrationSnapshot()->exists()) {
            throw new InvalidArgumentException('Migration snapshot already exists for this loan.');
        }

        $issuedDate = $data['issued_date'] ?? null;

        if (!$issuedDate) {
            throw new InvalidArgumentException('issued_date is required.');
        }

        $migrationDate = $data['migration_date']
            ?? $issuedDate
            ?? now($this->tz)->toDateString();

        $originalPrincipal = round((float) ($data['original_principal'] ?? 0), 2);
        $numberOfInstallments = (int) ($data['number_of_installments'] ?? 0);
        $paidInstallments = (int) ($data['paid_installments'] ?? 0);

        if ($originalPrincipal <= 0) {
            throw new InvalidArgumentException('original_principal must be greater than zero.');
        }

        if ($numberOfInstallments <= 0) {
            throw new InvalidArgumentException('number_of_installments must be greater than zero.');
        }

        if ($paidInstallments < 0) {
            throw new InvalidArgumentException('paid_installments cannot be negative.');
        }

        if ($paidInstallments > $numberOfInstallments) {
            throw new InvalidArgumentException('paid_installments cannot exceed number_of_installments.');
        }

        $installmentAmount = round($originalPrincipal / $numberOfInstallments, 2);
        $remainingInstallments = $numberOfInstallments - $paidInstallments;

        $computedOutstandingPrincipal = round($installmentAmount * $remainingInstallments, 2);

        $submittedOutstandingPrincipal = isset($data['outstanding_principal'])
            ? round((float) $data['outstanding_principal'], 2)
            : $computedOutstandingPrincipal;

        if ($submittedOutstandingPrincipal < 0) {
            throw new InvalidArgumentException('outstanding_principal cannot be negative.');
        }

        $outstandingPrincipal = $submittedOutstandingPrincipal;

        $principalPaidBeforeMigration = round($originalPrincipal - $outstandingPrincipal, 2);

        if ($principalPaidBeforeMigration < 0) {
            throw new InvalidArgumentException('principal_paid_before_migration cannot be negative.');
        }

        if ($principalPaidBeforeMigration > $originalPrincipal) {
            throw new InvalidArgumentException('principal_paid_before_migration cannot exceed original principal.');
        }

        if (round($principalPaidBeforeMigration + $outstandingPrincipal, 2) > $originalPrincipal) {
            throw new InvalidArgumentException(
                'Paid principal plus outstanding principal cannot exceed original principal.'
            );
        }

        $nextDueDate = $data['due_date'] ?? null;

        if ($remainingInstallments > 0 && !$nextDueDate) {
            throw new InvalidArgumentException('due_date is required when remaining installments exist.');
        }

        return DB::transaction(function () use (
            $loan,
            $data,
            $createdBy,
            $migrationDate,
            $originalPrincipal,
            $principalPaidBeforeMigration,
            $outstandingPrincipal,
            $numberOfInstallments,
            $paidInstallments,
            $remainingInstallments,
            $nextDueDate
        ) {
            $snapshot = LoanMigrationSnapshot::create([
                'loan_id' => $loan->id,

                'original_principal' => $originalPrincipal,
                'original_total_payable' => $originalPrincipal,

                // Historical only. Do NOT create cash/ledger income for this.
                'principal_paid_before_migration' => $principalPaidBeforeMigration,
                'interest_paid_before_migration' => 0,

                // This is the only balance brought into the new system.
                'outstanding_principal' => $outstandingPrincipal,
                'outstanding_interest' => 0,

                'migration_date' => $migrationDate,
                'note' => $data['note'] ?? null,
                'created_by' => $createdBy,
            ]);

            $loan->update([
                'is_migrated' => true,
                'status' => $loan->status === 'pending' ? 'active' : $loan->status,
            ]);

            if ($outstandingPrincipal > 0) {
                $this->transactionService->record(
                    type: 'opening_loan',
                    debit: $outstandingPrincipal,
                    credit: 0,
                    userId: (int) $loan->user_id,
                    reference: 'Migrated loan opening principal for Loan ID ' . $loan->id,
                    createdBy: $createdBy,
                    sourceType: 'loan_migration',
                    sourceId: (int) $loan->id,
                    beneficiaryId: $loan->beneficiary_id
                );
            }

            $this->createRemainingInstallments(
                loan: $loan,
                outstandingPrincipal: $outstandingPrincipal,
                numberOfInstallments: $numberOfInstallments,
                paidInstallments: $paidInstallments,
                remainingInstallments: $remainingInstallments,
                nextDueDate: $nextDueDate
            );

            return $snapshot->load('loan');
        });
    }

    private function createRemainingInstallments(
        Loan $loan,
        float $outstandingPrincipal,
        int $numberOfInstallments,
        int $paidInstallments,
        int $remainingInstallments,
        ?string $nextDueDate
    ): void {
        if ($outstandingPrincipal <= 0 || $remainingInstallments <= 0) {
            return;
        }

        LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->delete();

        $dueDate = Carbon::parse($nextDueDate, $this->tz)->startOfDay();

        $baseAmount = floor(($outstandingPrincipal / $remainingInstallments) * 100) / 100;
        $allocated = 0.0;

        for ($i = 1; $i <= $remainingInstallments; $i++) {
            $installmentNo = $paidInstallments + $i;

            if ($i === $remainingInstallments) {
                $amount = round($outstandingPrincipal - $allocated, 2);
            } else {
                $amount = round($baseAmount, 2);
                $allocated = round($allocated + $amount, 2);
            }

            LoanInstallment::create([
                'loan_id' => $loan->id,
                'installment_no' => $installmentNo,
                'due_date' => $dueDate->copy()->addMonths($i - 1)->toDateString(),

                'principal_amount' => $amount,
                'interest_amount' => 0,
                'total_amount' => $amount,
                'paid_amount' => 0,

                'status' => 'pending',
            ]);
        }
    }

    public function outstandingAfterMigration(Loan $loan): array
    {
        $loan->loadMissing('migrationSnapshot', 'repayments');

        $snapshot = $loan->migrationSnapshot;

        if (!$snapshot) {
            return [
                'outstanding_principal' => 0,
                'outstanding_interest' => 0,
                'total_outstanding' => 0,
            ];
        }

        $paidAfterMigration = round((float) $loan->repayments()->sum('amount'), 2);

        $openingPrincipal = round((float) $snapshot->outstanding_principal, 2);
        $remainingPrincipal = max(0, round($openingPrincipal - $paidAfterMigration, 2));

        return [
            'outstanding_principal' => $remainingPrincipal,
            'outstanding_interest' => 0,
            'total_outstanding' => $remainingPrincipal,
        ];
    }

    public function migrationSummary(Loan $loan): array
    {
        $loan->loadMissing('migrationSnapshot', 'repayments');

        $snapshot = $loan->migrationSnapshot;

        if (!$snapshot) {
            throw new InvalidArgumentException('This loan has no migration snapshot.');
        }

        $paidAfterMigration = round((float) $loan->repayments()->sum('amount'), 2);
        $current = $this->outstandingAfterMigration($loan);

        return [
            'loan_id' => (int) $loan->id,
            'is_migrated' => (bool) $loan->is_migrated,
            'migration_date' => $snapshot->migration_date,

            'original_principal' => round((float) $snapshot->original_principal, 2),

            'principal_paid_before_migration' => round((float) $snapshot->principal_paid_before_migration, 2),
            'interest_paid_before_migration' => 0.0,

            'opening_outstanding_principal' => round((float) $snapshot->outstanding_principal, 2),
            'opening_outstanding_interest' => 0.0,

            'paid_after_migration' => $paidAfterMigration,

            'current_outstanding_principal' => round((float) $current['outstanding_principal'], 2),
            'current_outstanding_interest' => 0.0,
            'current_total_outstanding' => round((float) $current['total_outstanding'], 2),

            'note' => $snapshot->note,
        ];
    }
}