<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\FinancialYearRule;
use App\Models\MemberFinancialYear;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MemberFinancialYearService
{
    public function activeRule(): FinancialYearRule
    {
        $rule = FinancialYearRule::where('is_active', true)->first();

        if (!$rule) {
            throw ValidationException::withMessages([
                'financial_year_rule' => 'No active financial year rule found.',
            ]);
        }

        return $rule;
    }

    public function getOrCreate(
        ?int $userId,
        ?int $beneficiaryId,
        int $financialYearRuleId
    ): MemberFinancialYear {
        $this->validateOwner($userId, $beneficiaryId);

        try {
            return DB::transaction(function () use ($userId, $beneficiaryId, $financialYearRuleId) {
                $mfy = $this->ownerQuery($userId, $beneficiaryId)
                    ->where('financial_year_rule_id', $financialYearRuleId)
                    ->lockForUpdate()
                    ->first();

                if ($mfy) {
                    return $mfy;
                }

                $mfy = new MemberFinancialYear([
                    'financial_year_rule_id' => $financialYearRuleId,
                    'user_id' => $userId,
                    'beneficiary_id' => $beneficiaryId,
                    'opening_balance' => 0,
                    'commitment_amount' => 0,
                ]);

                $mfy->save();

                return $mfy->fresh();
            });
        } catch (QueryException $e) {
            return $this->ownerQuery($userId, $beneficiaryId)
                ->where('financial_year_rule_id', $financialYearRuleId)
                ->firstOrFail();
        }
    }

    public function upsertSetup(
        ?int $userId,
        ?int $beneficiaryId,
        int $financialYearRuleId,
        float $openingBalance,
        float $commitmentAmount
    ): MemberFinancialYear {
        $this->validateOwner($userId, $beneficiaryId);

        if ($openingBalance < 0 || $commitmentAmount < 0) {
            throw ValidationException::withMessages([
                'amounts' => 'Opening balance and commitment must be >= 0.',
            ]);
        }

        return DB::transaction(function () use (
            $userId,
            $beneficiaryId,
            $financialYearRuleId,
            $openingBalance,
            $commitmentAmount
        ) {
            $mfy = $this->ownerQuery($userId, $beneficiaryId)
                ->where('financial_year_rule_id', $financialYearRuleId)
                ->lockForUpdate()
                ->first();

            if (!$mfy) {
                $mfy = new MemberFinancialYear([
                    'user_id' => $userId,
                    'beneficiary_id' => $beneficiaryId,
                    'financial_year_rule_id' => $financialYearRuleId,
                ]);
            }

            if ($mfy->closed_at) {
                throw ValidationException::withMessages([
                    'financial_year_rule_id' => 'This financial year is closed for the selected owner.',
                ]);
            }

            $mfy->opening_balance = round((float) $openingBalance, 2);
            $mfy->commitment_amount = round((float) $commitmentAmount, 2);
            $mfy->save();

            return $mfy->fresh();
        });
    }

    /**
     * closing_balance = opening_balance + sum(contributions within FY)
     */
    public function computeClosingBalance(
        ?int $userId,
        ?int $beneficiaryId,
        int $financialYearRuleId
    ): float {
        $this->validateOwner($userId, $beneficiaryId);

        $mfy = $this->ownerQuery($userId, $beneficiaryId)
            ->where('financial_year_rule_id', $financialYearRuleId)
            ->firstOrFail();

        $totalContrib = $this->ownerContributionQuery($userId, $beneficiaryId)
            ->where('financial_year_rule_id', $financialYearRuleId)
            ->sum('amount');

        return round((float) $mfy->opening_balance + (float) $totalContrib, 2);
    }

    public function close(
        ?int $userId,
        ?int $beneficiaryId,
        int $financialYearRuleId
    ): MemberFinancialYear {
        $this->validateOwner($userId, $beneficiaryId);

        return DB::transaction(function () use ($userId, $beneficiaryId, $financialYearRuleId) {
            $mfy = $this->ownerQuery($userId, $beneficiaryId)
                ->where('financial_year_rule_id', $financialYearRuleId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($mfy->closed_at) {
                throw ValidationException::withMessages([
                    'financial_year_rule_id' => 'This financial year is already closed for the selected owner.',
                ]);
            }

            $closing = $this->computeClosingBalance($userId, $beneficiaryId, $financialYearRuleId);

            $mfy->closing_balance = $closing;
            $mfy->closed_at = now();
            $mfy->save();

            return $mfy->fresh();
        });
    }

    public function reopen(
        ?int $userId,
        ?int $beneficiaryId,
        int $financialYearRuleId
    ): MemberFinancialYear {
        $this->validateOwner($userId, $beneficiaryId);

        return DB::transaction(function () use ($userId, $beneficiaryId, $financialYearRuleId) {
            $mfy = $this->ownerQuery($userId, $beneficiaryId)
                ->where('financial_year_rule_id', $financialYearRuleId)
                ->lockForUpdate()
                ->firstOrFail();

            $mfy->closing_balance = null;
            $mfy->closed_at = null;
            $mfy->save();

            return $mfy->fresh();
        });
    }

    protected function validateOwner(?int $userId, ?int $beneficiaryId): void
    {
        $hasUser = !is_null($userId);
        $hasBeneficiary = !is_null($beneficiaryId);

        if (($hasUser && $hasBeneficiary) || (!$hasUser && !$hasBeneficiary)) {
            throw ValidationException::withMessages([
                'owner' => 'Record must belong to either a user or a beneficiary.',
            ]);
        }
    }

    protected function ownerQuery(?int $userId, ?int $beneficiaryId)
    {
        return MemberFinancialYear::query()
            ->when(!is_null($userId), fn ($q) => $q->where('user_id', $userId))
            ->when(!is_null($beneficiaryId), fn ($q) => $q->where('beneficiary_id', $beneficiaryId));
    }

    protected function ownerContributionQuery(?int $userId, ?int $beneficiaryId)
    {
        return Contribution::query()
            ->when(!is_null($userId), fn ($q) => $q->where('user_id', $userId))
            ->when(!is_null($beneficiaryId), fn ($q) => $q->where('beneficiary_id', $beneficiaryId));
    }
}