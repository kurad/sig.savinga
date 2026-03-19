<?php

namespace App\Services;

use App\Models\Investment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class InvestmentService
{
    public function __construct(
        protected TransactionService $ledger
    ) {}

    public function createInvestment(
        string $name,
        ?string $description,
        float $totalAmount,
        string $investedDate,
        int $recordedBy
    ): Investment {
        return DB::transaction(function () use ($name, $description, $totalAmount, $investedDate, $recordedBy) {
             if (trim($name) === '') {
                throw new \InvalidArgumentException('Investment name is required.');
            }

            if ($totalAmount <= 0) {
                throw new \InvalidArgumentException('Total amount must be greater than 0.');
            }
            $availableBalance = round($this->ledger->availableCashBalance(), 2);

            if ($totalAmount > $availableBalance) {
            throw new \InvalidArgumentException(
                "Insufficient group balance. Available balance is {$availableBalance}."
            );
        }
            $investment = Investment::create([
                'name' => trim($name),
                'description' => $description,
                'total_amount' => round($totalAmount, 2),
                'invested_date' => $investedDate,
                'recorded_by' => $recordedBy,
            ]);

            // Record transaction for investment
            $this->ledger->record(
                type: 'investment',
                debit: round($totalAmount, 2),
                credit: 0,
                userId: null, // group investment
                reference: "Investment: {$investment->name}",
                createdBy: $recordedBy,
                sourceType: 'investment',
                sourceId: $investment->id
            );

            return $investment;
        });
    }

    public function sellInvestment(
        int $investmentId,
        float $saleAmount,
        string $saleDate,
        int $recordedBy
    ): Investment {
        return DB::transaction(function () use ($investmentId, $saleAmount, $saleDate, $recordedBy) {
            $investment = Investment::findOrFail($investmentId);

            if ($investment->status !== 'active') {
                throw new \Exception('Investment is not active.');
            }
            if ($saleAmount <= 0) {
                throw new \InvalidArgumentException('Sale amount cannot be negative.');
            }

            if (Carbon::parse($saleDate)->lt(Carbon::parse($investment->invested_date))) {
                throw new \InvalidArgumentException('Sale date cannot be before invested date.');
            }


            $saleAmount = round($saleAmount, 2);
            $profitLoss = round($saleAmount - (float) $investment->total_amount, 2);

            $investment->update([
                'status' => 'sold',
                'sale_date' => $saleDate,
                'sale_amount' => $saleAmount,
                'profit_loss' => $profitLoss,
            ]);

            // Record sale transaction
            $this->ledger->record(
                type: 'investment_sale',
                debit: 0,
                credit: $saleAmount,
                userId: null,
                reference: "Sale of Investment: {$investment->name}",
                createdBy: $recordedBy,
                sourceType: 'investment',
                sourceId: $investment->id
            );
            return $investment;
        });
    }

    public function getTotalInvested(): float
    {
        return (float) Investment::where('status', 'active')->sum('total_amount');
    }
}
