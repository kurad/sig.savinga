<?php

namespace App\Services;

use App\Models\Contribution;
use Carbon\Carbon;

class ContributionAllocationService
{
    /**
     * Build allocation rows for a member, applying carry-forward logic.
     *
     * @return array{
     *   meta: array{
     *     from: string,
     *     to: string,
     *     commitment_amount: float,
     *     paid_through_period: string|null,
     *     credit_balance: float
     *   },
     *   allocation: array<int, array{
     *     period_key: string,
     *     required: float,
     *     posted: float,
     *     forward_in: float,
     *     credited: float,
     *     status: string,
     *     carry_out: float
     *   }>
     * }
     */
    public function buildAllocation(int $memberId, float $commitmentAmount, string $fromPeriod, string $toPeriod): array
    {
        $tz = 'Africa/Kigali';

        $from = Carbon::createFromFormat('Y-m', $fromPeriod, $tz)->startOfMonth();
        $to   = Carbon::createFromFormat('Y-m', $toPeriod, $tz)->startOfMonth();

        // Ensure correct ordering
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $fromKey = $from->format('Y-m');
        $toKey   = $to->format('Y-m');

        // Sum posted per period_key (multiple rows per month are possible)
        $rows = Contribution::query()
            ->where('user_id', $memberId)
            ->whereBetween('period_key', [$fromKey, $toKey])
            ->selectRaw('period_key, SUM(amount) as total_amount')
            ->groupBy('period_key')
            ->get();

        $postedByPeriod = [];
        foreach ($rows as $r) {
            $postedByPeriod[$r->period_key] = (float) $r->total_amount;
        }

        $required = (float) $commitmentAmount;

        $allocation = [];
        $carry = 0.0;
        $paidThrough = null;

        // Iterate month-by-month
        $p = $from->copy();
        while ($p->lte($to)) {
            $k = $p->format('Y-m');

            $posted = (float) ($postedByPeriod[$k] ?? 0);
            $forwardIn = $carry;

            $available = $forwardIn + $posted;

            $credited = min($available, $required);

            $carryOut = max(0.0, $available - $required);

            $status = 'missed';
            if ($credited + 1e-9 >= $required) $status = 'funded';
            elseif ($credited > 0) $status = 'partial';

            if ($status === 'funded') {
                $paidThrough = $k;
            }

            $allocation[] = [
                'period_key' => $k,
                'required' => round($required, 2),
                'posted' => round($posted, 2),
                'forward_in' => round($forwardIn, 2),
                'credited' => round($credited, 2),
                'status' => $status,
                'carry_out' => round($carryOut, 2),
            ];

            $carry = $carryOut;
            $p->addMonth();
        }

        return [
            'meta' => [
                'from' => $from->format('Y-m'),
                'to' => $to->format('Y-m'),
                'commitment_amount' => round($required, 2),
                'paid_through_period' => $paidThrough,
                'credit_balance' => round($carry, 2),
            ],
            'allocation' => $allocation,
        ];
    }
}
