<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Income;
use App\Models\Expense;
use App\Models\Penalty;
use App\Models\ProfitCycle;
use App\Models\Contribution;
use App\Models\LoanRepayment;
use App\Models\ProfitDistribution;
use Illuminate\Support\Facades\DB;

class ProfitCycleService
{
    public function __construct(
        protected TransactionService $ledger
    ) {}

    public function open(string $startDate, string $endDate, int $openedBy): ProfitCycle
    {
        return DB::transaction(function () use ($startDate, $endDate, $openedBy) {

            // prevent multiple open cycles
            if (ProfitCycle::where('status', 'open')->exists()) {
                throw new \Exception('There is already an open profit cycle.');
            }

            // prevent overlap with existing cycles (recommended)
            $overlap = ProfitCycle::where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($q2) use ($startDate, $endDate) {
                        $q2->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
            })->exists();

            if ($overlap) {
                throw new \Exception('Profit cycle dates overlap an existing cycle.');
            }

            return ProfitCycle::create([
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'status'     => 'open',
                'opened_by'  => $openedBy,
            ]);
        });
    }

  public function closeAndDistribute(
    int $cycleId,
    float $extraOtherIncome,
    float $extraExpenses,
    string $distributionType,
    int $closedBy
): ProfitCycle {
    return DB::transaction(function () use (
        $cycleId,
        $extraOtherIncome,
        $extraExpenses,
        $distributionType,
        $closedBy
    ) {

        $cycle = ProfitCycle::lockForUpdate()->findOrFail($cycleId);

        if ($cycle->status !== 'open') {
            throw new \Exception('Profit cycle is not open.');
        }

        $start = Carbon::parse($cycle->start_date)->startOfDay();
        $end   = Carbon::parse($cycle->end_date)->endOfDay();

        // Interest income (use paid_date)
        $interestIncome = (float) LoanRepayment::query()
            ->whereBetween('paid_date', [$start, $end])
            ->sum('interest_component');

        // Penalty income (accrual style: created_at, excluding waived)
        $penaltyIncome = (float) Penalty::query()
            ->whereBetween('created_at', [$start, $end])
            ->where('status', '!=', 'waived')
            ->sum('amount');

        // Other income from incomes table + optional extra
        $otherIncomeFromTable = (float) Income::query()
            ->whereBetween('income_date', [$start->toDateString(), $end->toDateString()])
            ->sum('amount');

        $otherIncome = $otherIncomeFromTable + max(0, $extraOtherIncome);

        // Expenses from expenses table + optional extra
        $expensesFromTable = (float) Expense::query()
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->sum('amount');

        $expenses = $expensesFromTable + max(0, $extraExpenses);

        // Net profit
        $netProfit = ($interestIncome + $penaltyIncome + $otherIncome) - $expenses;
        if ($netProfit < 0) $netProfit = 0;

        // Distribution basis: contributions by expected_date (cycle)
        $memberContribs = Contribution::query()
            ->whereBetween('expected_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('user_id, SUM(amount) as total')
            ->groupBy('user_id')
            ->get();

        $totalContrib = (float) $memberContribs->sum('total');

        // Re-generate distributions
        ProfitDistribution::where('profit_cycle_id', $cycle->id)->delete();

        foreach ($memberContribs as $row) {
            $ratio = $totalContrib > 0 ? ((float) $row->total / $totalContrib) : 0;
            $amount = round($netProfit * $ratio, 2);

            ProfitDistribution::create([
                'profit_cycle_id'   => $cycle->id,
                'user_id'           => (int) $row->user_id,
                'amount'            => $amount,
                'share_ratio'       => $ratio,
                'distribution_type' => $distributionType,
                'status'            => 'pending',
            ]);
        }

        $cycle->update([
            'interest_income' => $interestIncome,
            'penalty_income'  => $penaltyIncome,
            'other_income'    => $otherIncome,
            'expenses'        => $expenses,
            'net_profit'      => $netProfit,
            'status'          => 'closed',
            'closed_by'       => $closedBy,
            'closed_at'       => now(),
        ]);

        return $cycle->fresh(['distributions.user:id,name,email,phone']);
    });
}


    public function creditDistribution(int $distributionId, int $resolvedBy, ?string $date = null): ProfitDistribution
    {
        return DB::transaction(function () use ($distributionId, $resolvedBy, $date) {

            $dist = ProfitDistribution::lockForUpdate()->findOrFail($distributionId);

            if ($dist->status !== 'pending') {
                return $dist;
            }

            $resolvedAt = $date ? Carbon::parse($date)->endOfDay() : now();

            $dist->update([
                'status'            => 'credited',
                'distribution_type' => 'credit',
                'resolved_at'       => $resolvedAt,
                'resolved_by'       => $resolvedBy,
            ]);

            // Ledger: profit credited to member (IN)
            $this->ledger->record(
                type: 'profit',
                debit: 0,
                credit: (float) $dist->amount,
                userId: $dist->user_id,
                reference: 'Profit credit for Cycle ID ' . $dist->profit_cycle_id,
                createdBy: $resolvedBy,
                sourceType: 'profit_distribution',
                sourceId: $dist->id
            );

            return $dist;
        });
    }

    public function payDistribution(int $distributionId, int $resolvedBy, ?string $date = null): ProfitDistribution
    {
        return DB::transaction(function () use ($distributionId, $resolvedBy, $date) {

            $dist = ProfitDistribution::lockForUpdate()->findOrFail($distributionId);

            if ($dist->status !== 'pending') {
                return $dist;
            }

            $resolvedAt = $date ? Carbon::parse($date)->endOfDay() : now();

            $dist->update([
                'status'            => 'paid',
                'distribution_type' => 'cash',
                'resolved_at'       => $resolvedAt,
                'resolved_by'       => $resolvedBy,
            ]);

            // Ledger: profit payout (OUT)
            $this->ledger->record(
                type: 'profit',
                debit: (float) $dist->amount,
                credit: 0,
                userId: $dist->user_id,
                reference: 'Profit payout for Cycle ID ' . $dist->profit_cycle_id,
                createdBy: $resolvedBy,
                sourceType: 'profit_distribution',
                sourceId: $dist->id
            );

            return $dist;
        });
    }
    public function creditAllDistributions(int $cycleId, int $resolvedBy, ?string $date = null): array
    {
        return DB::transaction(function () use ($cycleId, $resolvedBy, $date) {

            $cycle = ProfitCycle::lockForUpdate()->findOrFail($cycleId);

            if ($cycle->status !== 'closed') {
                throw new \Exception('Cycle must be closed before resolving distributions.');
            }

            $resolvedAt = $date ? Carbon::parse($date)->endOfDay() : now();

            $pending = ProfitDistribution::lockForUpdate()
                ->where('profit_cycle_id', $cycle->id)
                ->where('status', 'pending')
                ->get();

            $count = 0;
            $total = 0.0;

            foreach ($pending as $dist) {
                $dist->update([
                    'status'            => 'credited',
                    'distribution_type' => 'credit',
                    'resolved_at'       => $resolvedAt,
                    'resolved_by'       => $resolvedBy,
                ]);

                $this->ledger->record(
                    type: 'profit',
                    debit: 0,
                    credit: (float) $dist->amount,
                    userId: $dist->user_id,
                    reference: 'Profit credit (bulk) for Cycle ID ' . $dist->profit_cycle_id,
                    createdBy: $resolvedBy,
                    sourceType: 'profit_distribution',
                    sourceId: $dist->id
                );

                $count++;
                $total += (float) $dist->amount;
            }

            return [
                'cycle_id' => $cycle->id,
                'action'   => 'credit_all',
                'count'    => $count,
                'total'    => round($total, 2),
                'resolved_at' => $resolvedAt->toDateTimeString(),
            ];
        });
    }

    public function payAllDistributions(int $cycleId, int $resolvedBy, ?string $date = null): array
    {
        return DB::transaction(function () use ($cycleId, $resolvedBy, $date) {

            $cycle = ProfitCycle::lockForUpdate()->findOrFail($cycleId);

            if ($cycle->status !== 'closed') {
                throw new \Exception('Cycle must be closed before resolving distributions.');
            }

            $resolvedAt = $date ? Carbon::parse($date)->endOfDay() : now();

            $pending = ProfitDistribution::lockForUpdate()
                ->where('profit_cycle_id', $cycle->id)
                ->where('status', 'pending')
                ->get();

            $count = 0;
            $total = 0.0;

            foreach ($pending as $dist) {
                $dist->update([
                    'status'            => 'paid',
                    'distribution_type' => 'cash',
                    'resolved_at'       => $resolvedAt,
                    'resolved_by'       => $resolvedBy,
                ]);

                $this->ledger->record(
                    type: 'profit',
                    debit: (float) $dist->amount,
                    credit: 0,
                    userId: $dist->user_id,
                    reference: 'Profit payout (bulk) for Cycle ID ' . $dist->profit_cycle_id,
                    createdBy: $resolvedBy,
                    sourceType: 'profit_distribution',
                    sourceId: $dist->id
                );

                $count++;
                $total += (float) $dist->amount;
            }

            return [
                'cycle_id' => $cycle->id,
                'action'   => 'pay_all',
                'count'    => $count,
                'total'    => round($total, 2),
                'resolved_at' => $resolvedAt->toDateTimeString(),
            ];
        });
    }
}
