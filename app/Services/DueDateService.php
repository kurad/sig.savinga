<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\FinancialYearRule;
use Carbon\Carbon;

class DueDateService
{
    public function getActiveYear(): FinancialYearRule
    {
        return FinancialYearRule::query()
            ->where('is_active', true)
            ->firstOrFail();
    }

    /**
     * Decide next due period based on whether current period is fully funded.
     * - If current month envelope is fully funded (amount >= commitment_amount) -> next month
     * - Else -> this month
     *
     * $commitmentAmount is required because your system uses commitment per period.
     */
    public function computeNextDueForMember(int $memberId, float $commitmentAmount, ?string $today = null): array
    {
        $fy = $this->getActiveYear();
        $tz = 'Africa/Kigali';

        $now = $today ? Carbon::parse($today, $tz) : Carbon::now($tz);

        $fyStart = Carbon::parse($fy->start_date, $tz)->startOfMonth();
        $fyEnd   = Carbon::parse($fy->end_date, $tz)->startOfMonth();

        $offset    = (int) ($fy->due_month_offset ?? 0);
        $dueDay    = (int) ($fy->due_day ?? 1);
        $graceDays = (int) ($fy->grace_days ?? 0);

        $commit = (float) $commitmentAmount;

        // ---------------------------------------
        // Pull ALL contributions in the FY and SUM per period_key
        // (If multiple payments happen in one month, we must sum them)
        // ---------------------------------------
        $startKey = $fyStart->format('Y-m');
        $endKey   = $fyEnd->format('Y-m');

        $rows = Contribution::query()
            ->where('user_id', $memberId)
            ->whereBetween('period_key', [$startKey, $endKey])
            ->selectRaw('period_key, SUM(amount) as total_amount')
            ->groupBy('period_key')
            ->get();

        $amountByPeriod = [];
        foreach ($rows as $r) {
            $amountByPeriod[$r->period_key] = (float) $r->total_amount;
        }

        // ---------------------------------------
        // Allocate contributions forward from FY start, using carry
        // ---------------------------------------
        $carry = 0.0;
        $paidThrough = null;

        $nextDuePeriod = null;
        $creditedTowardPeriod = 0.0; // includes carry
        $periodPostedAmount = 0.0;   // amount posted in that period_key (raw)
        $forwardCredit = 0.0;        // carry coming into next due period

        $p = $fyStart->copy();
        while ($p->lte($fyEnd)) {
            $k = $p->format('Y-m');
            $posted = (float) ($amountByPeriod[$k] ?? 0);

            // carry enters the period
            $forwardIn = $carry;

            // available funds to cover this period
            $available = $forwardIn + $posted;

            if ($available + 1e-9 < $commit) {
                // first unpaid period
                $nextDuePeriod = $p->copy();
                $forwardCredit = round($forwardIn, 2);
                $periodPostedAmount = round($posted, 2);

                // what is actually credited toward this period so far (including forward)
                $creditedTowardPeriod = round($available, 2);

                // carry does not go negative
                $carry = 0.0;
                break;
            }

            // fully covers this period, compute carry out
            $availableAfter = $available - $commit;
            $carry = $availableAfter;

            // paid through this period
            $paidThrough = $p->format('Y-m');

            $p->addMonth();
        }

        // If everything in FY is fully funded, treat next due as FY end (no amount due)
        // (You can also set it to fyEnd+1 month, but clamping is safer)
        if (!$nextDuePeriod) {
            $nextDuePeriod = $fyEnd->copy();
            $forwardCredit = round($carry, 2); // remaining credit after funding all periods
            $periodPostedAmount = round((float) ($amountByPeriod[$nextDuePeriod->format('Y-m')] ?? 0), 2);

            // If you're fully funded through FY end, amount due should be 0
            $creditedTowardPeriod = round($commit, 2);
        }

        $nextKey = $nextDuePeriod->format('Y-m');

        // If we found an unpaid period, compute amount due based on creditedTowardPeriod
        // Else amountDue is 0 (fully funded)
        $amountDue = max(0, round($commit - (float) $creditedTowardPeriod, 2));

        // Due date belongs to "due month" = duePeriod + offset
        $dueMonth = $nextDuePeriod->copy()->addMonths($offset);

        // clamp due day to last day of dueMonth
        $lastDay  = (int) $dueMonth->copy()->endOfMonth()->day;
        $finalDay = min($dueDay, $lastDay);

        $dueDate = $dueMonth->copy()->day($finalDay)->startOfDay();

        $overdueFrom    = $dueDate->copy()->addDays($graceDays);
        $daysRemaining  = $now->copy()->startOfDay()->diffInDays($dueDate, false);
        $isOverdue      = $now->copy()->startOfDay()->gt($overdueFrom);

        $hint = 'ok';
        if ($isOverdue) $hint = 'overdue';
        elseif ($daysRemaining <= 5) $hint = 'due_soon';

        // already_funded for UI = what is credited toward this period (includes forward credit)
        // BUT if you also want "posted in this period only", use period_posted_amount
        return [
            'financial_year' => $fy->year_key,
            'due_day' => $fy->due_day,
            'due_month_offset' => $offset,
            'grace_days' => $fy->grace_days,

            'next_due_period' => $nextKey,
            'next_due_date' => $dueDate->toDateString(),

            'commitment_amount' => round($commit, 2),

            // credited toward the period, including forward carry
            'already_funded' => round((float) $creditedTowardPeriod, 2),

            // extra money carried into this period from earlier overpayments
            'forward_credit' => round((float) $forwardCredit, 2),

            // how much user posted specifically under next_due_period (raw)
            'period_posted_amount' => round((float) $periodPostedAmount, 2),

            // remaining credit after funding periods up to paidThrough (carry balance)
            'credit_balance' => round((float) $carry, 2),

            'paid_through_period' => $paidThrough,

            'amount_due' => $amountDue,

            'days_remaining' => $daysRemaining,
            'is_overdue' => $isOverdue,
            'hint' => $hint,
        ];
    }
}
