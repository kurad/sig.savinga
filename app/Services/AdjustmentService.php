<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\Contribution;
use App\Models\Loan;
use App\Models\OpeningBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustmentService
{
    public function __construct(
        protected TransactionService $transactionService
    ) {}

    public function create(Model $model, array $data, int $adminId): Adjustment
    {
        return DB::transaction(function () use ($model, $data, $adminId) {
            $amount = round((float) ($data['amount'] ?? 0), 2);
            $reason = trim((string) ($data['reason'] ?? ''));

            if ($amount == 0.0) {
                throw ValidationException::withMessages([
                    'amount' => ['Adjustment amount cannot be zero.'],
                ]);
            }

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => ['Reason is required.'],
                ]);
            }

            [$userId, $beneficiaryId, $asOfPeriod, $transactionType, $reference] =
                $this->resolveAdjustmentMeta($model, $reason);

            $adjustment = Adjustment::create([
                'user_id'         => $userId,
                'beneficiary_id'  => $beneficiaryId,
                'as_of_period'    => $asOfPeriod,
                'amount'          => $amount,
                'reason'          => $reason,
                'created_by'      => $adminId,
                'adjustable_type' => $model->getMorphClass(),
                'adjustable_id'   => $model->getKey(),
            ]);

            $tx = $this->transactionService->record(
                type: $transactionType,
                debit: $amount < 0 ? abs($amount) : 0,
                credit: $amount > 0 ? $amount : 0,
                userId: $userId,
                reference: $reference,
                createdBy: $adminId,
                sourceType: 'adjustment',
                sourceId: $adjustment->id,
                beneficiaryId: $beneficiaryId
            );

            $adjustment->update([
                'transaction_id' => $tx->id,
            ]);

            $this->applyBusinessSideEffects($model, $amount);

            return $adjustment->fresh();
        });
    }

    protected function resolveAdjustmentMeta(Model $model, string $reason): array
    {
        if ($model instanceof OpeningBalance) {
            return [
                (int) $model->user_id,
                $model->beneficiary_id ? (int) $model->beneficiary_id : null,
                $model->as_of_period,
                'opening_balance_adjustment',
                $model->beneficiary_id
                    ? "Opening balance adjustment for beneficiary as of {$model->as_of_period} — {$reason}"
                    : "Opening balance adjustment as of {$model->as_of_period} — {$reason}",
            ];
        }

        if ($model instanceof Contribution) {
            return [
                (int) $model->user_id,
                $model->beneficiary_id ? (int) $model->beneficiary_id : null,
                $model->period_key ?? null,
                'contribution_adjustment',
                "Contribution adjustment" . ($model->period_key ? " for {$model->period_key}" : "") . " — {$reason}",
            ];
        }

        if ($model instanceof Loan) {
            $repaymentsCount = method_exists($model, 'repayments')
                ? $model->repayments()->count()
                : 0;

            if ($repaymentsCount > 0) {
                throw ValidationException::withMessages([
                    'loan' => ['Loan cannot be adjusted after repayments have started. Use reversal/restructure flow.'],
                ]);
            }

            return [
                (int) $model->user_id,
                $model->beneficiary_id ? (int) $model->beneficiary_id : null,
                null,
                'loan_adjustment',
                "Loan adjustment for Loan #{$model->id} — {$reason}",
            ];
        }

        throw ValidationException::withMessages([
            'adjustable_type' => ['This record type does not support adjustments.'],
        ]);
    }

    protected function applyBusinessSideEffects(Model $model, float $amount): void
    {
        if ($model instanceof OpeningBalance) {
            return;
        }

        if ($model instanceof Contribution) {
            $model->amount = round((float) $model->amount + $amount, 2);

            if ($model->amount < 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Contribution effective amount cannot go below zero.'],
                ]);
            }

            $model->save();
            return;
        }

        if ($model instanceof Loan) {
            $model->principal = round((float) $model->principal + $amount, 2);

            if ($model->principal <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Loan effective principal must remain greater than zero.'],
                ]);
            }

            if (isset($model->interest_amount) && isset($model->total_payable)) {
                $oldPrincipal = round((float) $model->principal - $amount, 2);
                $principalDelta = $model->principal - $oldPrincipal;

                $model->total_payable = round((float) $model->total_payable + $principalDelta, 2);
            }

            $model->save();
            return;
        }
    }
}