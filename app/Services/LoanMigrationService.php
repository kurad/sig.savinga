<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanMigrationSnapshot;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LoanMigrationService
{
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

        if (empty($data['migration_date'])) {
            throw new InvalidArgumentException('migration_date is required.');
        }

        $originalPrincipal = round((float) ($data['original_principal'] ?? 0), 2);
        $originalTotalPayable = isset($data['original_total_payable']) && $data['original_total_payable'] !== null
            ? round((float) $data['original_total_payable'], 2)
            : null;

        $principalPaidBeforeMigration = round((float) ($data['principal_paid_before_migration'] ?? 0), 2);
        $interestPaidBeforeMigration = round((float) ($data['interest_paid_before_migration'] ?? 0), 2);
        $outstandingPrincipal = round((float) ($data['outstanding_principal'] ?? 0), 2);
        $outstandingInterest = round((float) ($data['outstanding_interest'] ?? 0), 2);

        if ($originalPrincipal <= 0) {
            throw new InvalidArgumentException('original_principal must be greater than zero.');
        }

        if ($principalPaidBeforeMigration < 0 || $interestPaidBeforeMigration < 0) {
            throw new InvalidArgumentException('Paid amounts cannot be negative.');
        }

        if ($outstandingPrincipal < 0) {
            throw new InvalidArgumentException('Outstanding principal cannot be negative.');
        }

        if ($outstandingInterest < 0) {
            throw new InvalidArgumentException('Outstanding interest cannot be negative.');
        }

        if ($outstandingInterest > 0) {
            throw new InvalidArgumentException(
                'For this group, outstanding interest must be zero because interest is deducted upfront before disbursement.'
            );
        }

        if ($principalPaidBeforeMigration > $originalPrincipal) {
            throw new InvalidArgumentException('Principal paid before migration cannot exceed original principal.');
        }

        if (round($principalPaidBeforeMigration + $outstandingPrincipal, 2) > $originalPrincipal) {
            throw new InvalidArgumentException(
                'Principal paid before migration plus outstanding principal cannot exceed original principal.'
            );
        }

        if ($originalTotalPayable !== null) {
            $totalPaidBeforeMigration = round($principalPaidBeforeMigration + $interestPaidBeforeMigration, 2);

            if ($totalPaidBeforeMigration > $originalTotalPayable) {
                throw new InvalidArgumentException('Total paid before migration cannot exceed original total payable.');
            }
        }

        return DB::transaction(function () use (
            $loan,
            $data,
            $createdBy,
            $originalPrincipal,
            $originalTotalPayable,
            $principalPaidBeforeMigration,
            $interestPaidBeforeMigration,
            $outstandingPrincipal
        ) {
            $snapshot = LoanMigrationSnapshot::create([
                'loan_id' => $loan->id,
                'original_principal' => $originalPrincipal,
                'original_total_payable' => $originalTotalPayable,
                'principal_paid_before_migration' => $principalPaidBeforeMigration,
                'interest_paid_before_migration' => $interestPaidBeforeMigration,
                'outstanding_principal' => $outstandingPrincipal,
                'outstanding_interest' => 0,
                'migration_date' => $data['migration_date'],
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

            return $snapshot->load('loan');
        });
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
            'original_total_payable' => $snapshot->original_total_payable !== null
                ? round((float) $snapshot->original_total_payable, 2)
                : null,
            'principal_paid_before_migration' => round((float) $snapshot->principal_paid_before_migration, 2),
            'interest_paid_before_migration' => round((float) $snapshot->interest_paid_before_migration, 2),
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