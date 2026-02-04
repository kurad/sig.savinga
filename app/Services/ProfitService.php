<?php

namespace App\Services;

use Exception;
use Carbon\Carbon;
use App\Models\User;
use App\Models\SystemRule;
use App\Models\ProfitCycle;
use App\Models\Transaction;
use App\Models\LoanRepayment;
use App\Models\ProfitDistribution;
use Illuminate\Support\Facades\DB;

class ProfitService
{
    public function __construct(
        protected TransactionService $ledger
    ) {}

    /**
     * Open a profit cycle (optional helper)
     */
    public function openCycle(string $startDate, string $endDate): ProfitCycle
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end   = Carbon::parse($endDate)->endOfDay();

        // Only one open cycle at a time
        $open = ProfitCycle::where('status', 'open')->first();
        if ($open) {
            throw new Exception('There is already an open profit cycle.');
        }

        if ($end->lte($start)) {
            throw new Exception('End date must be after start date.');
        }

        return ProfitCycle::create([
            'start_date'   => $start->toDateString(),
            'end_date'     => $end->toDateString(),
            'total_profit' => 0,
            'status'       => 'open',
        ]);
    }

    /**
     * Calculate profit for a cycle (cash-basis)
     *
     * Profit = (loan repayments - loan disbursements) + penalties
     *
     * NOTE: This is cash-basis and may not match "accrued interest" if loans span cycles.
     */
    public function calculateProfit(ProfitCycle $cycle): float
    {
        $start = Carbon::parse($cycle->start_date)->startOfDay();
        $end   = Carbon::parse($cycle->end_date)->endOfDay();

        // Interest received in this cycle
        $interestReceived = (float) LoanRepayment::whereBetween('created_at', [$start, $end])
            ->sum('interest_component');

        // Penalties received in this cycle (ledger-based)
        $penaltiesReceived = (float) Transaction::where('type', 'penalty')
            ->whereBetween('created_at', [$start, $end])
            ->sum('credit');

        $profit = $interestReceived + $penaltiesReceived;

        return round(max(0, $profit), 2);
    }

    /**
     * Close a profit cycle: compute profit, generate distributions (pending or paid)
     */
    public function closeCycleAndGenerateDistributions(
        int $cycleId,
        string $distributionType, // 'cash' or 'savings'
        int $recordedBy,
        bool $autoPay = false
    ): ProfitCycle {
        return DB::transaction(function () use ($cycleId, $distributionType, $recordedBy, $autoPay) {

            if (!in_array($distributionType, ['cash', 'savings'], true)) {
                throw new Exception('Invalid distribution type. Use cash or savings.');
            }

            /** @var ProfitCycle $cycle */
            $cycle = ProfitCycle::lockForUpdate()->findOrFail($cycleId);

            if ($cycle->status !== 'open') {
                throw new Exception('Only an open cycle can be closed.');
            }

            $rules = SystemRule::firstOrFail();

            $profit = $this->calculateProfit($cycle);

            // Update cycle totals & close it
            $cycle->update([
                'total_profit' => $profit,
                'status'       => 'closed',
            ]);

            if ($profit <= 0) {
                return $cycle; // nothing to distribute
            }

            // Choose members to share with (active only)
            $members = User::where('status', 'active')->get(['id']);

            if ($members->count() === 0) {
                throw new Exception('No active members found to distribute profit.');
            }

            // Build distribution amounts
            $shares = $this->calculateMemberShares(
                rulesMethod: $rules->profit_share_method, // 'equal' or 'savings_ratio'
                cycle: $cycle,
                memberIds: $members->pluck('id')->all(),
                totalProfit: $profit
            );

            // Create ProfitDistribution rows
            foreach ($shares as $userId => $amount) {
                ProfitDistribution::create([
                    'profit_cycle_id'    => $cycle->id,
                    'user_id'            => $userId,
                    'amount'             => $amount,
                    'distribution_type'  => $distributionType,
                    'status'             => $autoPay ? 'paid' : 'pending',
                ]);
            }

            // Optionally auto-pay (posts ledger entries)
            if ($autoPay) {
                $this->payDistributions($cycle->id, $recordedBy);
            }

            return $cycle;
        });
    }

    /**
     * Pay pending distributions for a cycle (posts ledger entries)
     */
    public function payDistributions(int $cycleId, int $recordedBy): void
    {
        DB::transaction(function () use ($cycleId, $recordedBy) {

            /** @var ProfitCycle $cycle */
            $cycle = ProfitCycle::lockForUpdate()->findOrFail($cycleId);

            if ($cycle->status !== 'closed') {
                throw new Exception('Cycle must be closed before paying distributions.');
            }

            $dists = ProfitDistribution::lockForUpdate()
                ->where('profit_cycle_id', $cycleId)
                ->where('status', 'pending')
                ->get();

            if ($dists->isEmpty()) {
                return;
            }

            // If paying in cash, ensure group has enough cash balance
            $cashTotal = $dists->where('distribution_type', 'cash')->sum('amount');
            if ($cashTotal > 0) {
                $available = $this->ledger->groupBalance();
                if ($available < $cashTotal) {
                    throw new Exception('Insufficient group balance to pay cash distributions.');
                }
            }

            foreach ($dists as $dist) {
                $amount = (float)$dist->amount;

                if ($amount <= 0) {
                    $dist->update(['status' => 'paid']);
                    continue;
                }

                if ($dist->distribution_type === 'cash') {
                    // Money leaves the group to the member
                    $this->ledger->record(
                        type: 'profit',
                        debit: $amount,
                        credit: 0,
                        userId: $dist->user_id,
                        reference: 'Profit cash payout - Cycle ' . $cycleId,
                        createdBy: $recordedBy
                    );
                } else {
                    // Savings allocation: net-zero group cash, but member gets credited
                    // Debit (pool)
                    $this->ledger->record(
                        type: 'profit',
                        debit: $amount,
                        credit: 0,
                        userId: $dist->user_id,
                        reference: 'Profit allocated to savings (pool) - Cycle ' . $cycleId,
                        createdBy: $recordedBy,
                        sourceType: 'profit_distribution',
                        sourceId: $dist->id
                    );

                    // Credit (member)
                    $this->ledger->record(
                        type: 'profit',
                        debit: 0,
                        credit: $amount,
                        userId: $dist->user_id,
                        reference: 'Profit allocated to savings - Cycle ' . $cycleId,
                        createdBy: $recordedBy
                    );
                }

                $dist->update(['status' => 'paid']);
            }
        });
    }

    /**
     * Calculate how much each member gets.
     * Methods:
     *  - equal: profit / members
     *  - savings_ratio: based on contributions within the cycle
     */
    protected function calculateMemberShares(
        string $rulesMethod,
        ProfitCycle $cycle,
        array $memberIds,
        float $totalProfit
    ): array {
        $shares = [];

        if ($rulesMethod === 'equal') {
            $per = round($totalProfit / count($memberIds), 2);
            // Handle rounding remainder by adding it to first member
            $total = $per * count($memberIds);
            $remainder = round($totalProfit - $total, 2);

            foreach ($memberIds as $i => $id) {
                $shares[$id] = $per + ($i === 0 ? $remainder : 0);
            }

            return $shares;
        }

        if ($rulesMethod !== 'savings_ratio') {
            throw new Exception('Invalid profit_share_method in SystemRule.');
        }

        // savings_ratio: weight = member contributions during the cycle
        $start = Carbon::parse($cycle->start_date)->startOfDay();
        $end   = Carbon::parse($cycle->end_date)->endOfDay();

        $weights = [];
        $totalWeight = 0.0;

        foreach ($memberIds as $id) {
            $w = (float) Transaction::where('type', 'contribution')
                ->where('user_id', $id)
                ->whereBetween('created_at', [$start, $end])
                ->sum('credit');

            $weights[$id] = $w;
            $totalWeight += $w;
        }

        // If everyone has 0 contributions in the cycle, fallback to equal
        if ($totalWeight <= 0) {
            $per = round($totalProfit / count($memberIds), 2);
            foreach ($memberIds as $id) {
                $shares[$id] = $per;
            }
            return $shares;
        }

        // Proportional shares
        $running = 0.0;
        $ids = array_values($memberIds);

        foreach ($ids as $index => $id) {
            if ($index === count($ids) - 1) {
                // last member gets remainder to fix rounding
                $shares[$id] = round($totalProfit - $running, 2);
                break;
            }

            $portion = round(($weights[$id] / $totalWeight) * $totalProfit, 2);
            $shares[$id] = $portion;
            $running += $portion;
        }

        return $shares;
    }
}
