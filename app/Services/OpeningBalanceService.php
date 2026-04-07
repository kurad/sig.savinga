<?php

namespace App\Services;

use App\Models\FinancialYearRule;
use App\Models\OpeningBalance;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class OpeningBalanceService
{
    protected string $tz = 'Africa/Kigali';

    public function __construct(
        protected TransactionService $transactionService
    ) {}

    public function setOpeningBalance(array $data, int $adminId): OpeningBalance
    {
        return DB::transaction(function () use ($data, $adminId) {
            $userId = (int) $data['user_id'];

            $beneficiaryId = isset($data['beneficiary_id']) && $data['beneficiary_id'] !== null
                ? (int) $data['beneficiary_id']
                : null;

            $asOf = (string) $data['as_of_period'];
            $amount = round((float) $data['amount'], 2);
            $note = $data['note'] ?? null;

            $this->validateOwner($userId, $beneficiaryId);

            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $asOf)) {
                throw ValidationException::withMessages([
                    'as_of_period' => ['The as_of_period must be in YYYY-MM format.'],
                ]);
            }

            if ($amount == 0.0) {
                throw ValidationException::withMessages([
                    'amount' => ['Opening balance cannot be zero.'],
                ]);
            }

            $existingQuery = OpeningBalance::query()
                ->where('user_id', $userId)
                ->where('as_of_period', $asOf);

            if ($beneficiaryId !== null) {
                $existingQuery->where('beneficiary_id', $beneficiaryId);
            } else {
                $existingQuery->whereNull('beneficiary_id');
            }

            $existing = $existingQuery->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    $beneficiaryId !== null ? 'beneficiary_id' : 'user_id' => [
                        $beneficiaryId !== null
                            ? 'Opening balance already set for this beneficiary in this period. Use an adjustment/correction flow if needed.'
                            : 'Opening balance already set for this member in this period. Use an adjustment/correction flow if needed.',
                    ],
                ]);
            }

            $openingBalance = OpeningBalance::create([
                'user_id'        => $userId,
                'beneficiary_id' => $beneficiaryId,
                'as_of_period'   => $asOf,
                'amount'         => $amount,
                'note'           => $note,
                'created_by'     => $adminId,
            ]);

            $reference = $beneficiaryId !== null
                ? "Beneficiary opening balance as of {$asOf}" . ($note ? " — {$note}" : "")
                : "Opening balance as of {$asOf}" . ($note ? " — {$note}" : "");

            $transaction = $this->transactionService->record(
                type: 'opening_balance',
                debit: $amount < 0 ? abs($amount) : 0,
                credit: $amount > 0 ? $amount : 0,
                userId: $userId,
                reference: $reference,
                createdBy: $adminId,
                sourceType: 'opening_balance',
                sourceId: $openingBalance->id,
                beneficiaryId: $beneficiaryId
            );

            $openingBalance->update([
                'transaction_id' => $transaction->id,
            ]);

            return $openingBalance->fresh();
        });
    }

    public function openingBalancesForOwner(
        int $userId,
        ?int $beneficiaryId = null,
        ?int $financialYearRuleId = null
    ): Collection {
        $this->validateOwner($userId, $beneficiaryId);

        $fy = $this->resolveFy($financialYearRuleId);

        $startPeriod = Carbon::parse($fy->start_date, $this->tz)->format('Y-m');
        $endPeriod   = Carbon::parse($fy->end_date, $this->tz)->format('Y-m');

        $query = OpeningBalance::query()
            ->where('user_id', $userId)
            ->whereBetween('as_of_period', [$startPeriod, $endPeriod])
            ->with('adjustments');

        if (is_null($beneficiaryId)) {
            $query->whereNull('beneficiary_id');
        } else {
            $query->where('beneficiary_id', $beneficiaryId);
        }

        return $query->orderBy('as_of_period')->get();
    }

    public function openingBalanceForOwner(
        int $userId,
        ?int $beneficiaryId = null,
        ?int $financialYearRuleId = null
    ): float {
        $rows = $this->openingBalancesForOwner($userId, $beneficiaryId, $financialYearRuleId);

        return round((float) $rows->sum(function ($row) {
            return $this->effectiveAmount($row);
        }), 2);
    }

    public function effectiveAmount(OpeningBalance $openingBalance): float
    {
        $base = round((float) $openingBalance->amount, 2);

        $adjustmentsTotal = $openingBalance->relationLoaded('adjustments')
            ? round((float) $openingBalance->adjustments->sum('amount'), 2)
            : round((float) $openingBalance->adjustments()->sum('amount'), 2);

        return round($base + $adjustmentsTotal, 2);
    }

    protected function resolveFy(?int $financialYearRuleId): FinancialYearRule
    {
        return $financialYearRuleId
            ? FinancialYearRule::findOrFail($financialYearRuleId)
            : FinancialYearRule::where('is_active', true)->firstOrFail();
    }

    protected function validateOwner(int $userId, ?int $beneficiaryId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('user_id is required and must be a valid positive integer.');
        }

        if (!is_null($beneficiaryId) && $beneficiaryId <= 0) {
            throw new InvalidArgumentException('beneficiary_id must be a valid positive integer when provided.');
        }
    }
}