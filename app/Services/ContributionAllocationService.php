<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\FinancialYearRule;
use Carbon\Carbon;

class ContributionAllocationService
{
    public function buildAllocation(int $memberId, float $commitmentAmount, string $fromPeriod, string $toPeriod): array
    {
        $tz = 'Africa/Kigali';

        $from = Carbon::createFromFormat('Y-m', $fromPeriod, $tz)->startOfMonth();
        $to   = Carbon::createFromFormat('Y-m', $toPeriod, $tz)->startOfMonth();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $fy = FinancialYearRule::query()
            ->where('is_active', true)
            ->firstOrFail();

        $fromKey = $from->format('Y-m');
        $toKey   = $to->format('Y-m');

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
        $today = now($tz)->startOfDay();

        $p = $from->copy();

        while ($p->lte($to)) {
            $k = $p->format('Y-m');

            $posted = (float) ($postedByPeriod[$k] ?? 0);
            $forwardIn = $carry;
            $available = $forwardIn + $posted;

            $credited = min($available, $required);
            $carryOut = max(0.0, $available - $required);

            $dueDate = $this->dueDateForPeriod($fy, $k, $tz);

            if ($credited + 1e-9 >= $required) {
                $status = 'funded';
                $paidThrough = $k;
            } elseif ($today->gt($dueDate)) {
                $status = 'missed';
            } else {
                $status = 'partial';
            }

            $allocation[] = [
                'period_key' => $k,
                'required' => round($required, 2),
                'posted' => round($posted, 2),
                'forward_in' => round($forwardIn, 2),
                'credited' => round($credited, 2),
                'status' => $status,
                'carry_out' => round($carryOut, 2),
                'due_date' => $dueDate->toDateString(),
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

    private function dueDateForPeriod(FinancialYearRule $fy, string $periodKey, string $tz): Carbon
    {
        $offset = (int) ($fy->due_month_offset ?? 0);
        $dueDay = (int) ($fy->due_day ?? 1);
        $graceDays = (int) ($fy->grace_days ?? 0);

        $dueMonth = Carbon::createFromFormat('Y-m-d', $periodKey . '-01', $tz)
            ->startOfMonth()
            ->addMonths($offset);

        $lastDay = $dueMonth->copy()->endOfMonth()->day;

        return $dueMonth
            ->day(min($dueDay, $lastDay))
            ->addDays($graceDays)
            ->startOfDay();
    }
}