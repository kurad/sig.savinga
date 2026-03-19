<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\MemberFinancialYearService;

class MemberFinancialYearController extends Controller
{
    private function ensureAllowed(Request $request, array $roles): void
    {
        $role = $request->user()->role ?? null;

        if (!in_array($role, $roles, true)) {
            abort(403, 'Forbidden');
        }
    }

    private function resolveOwnerFromRequest(Request $request): array
    {
        $ownerType = $request->input('owner_type');

        return [
            'owner_type' => $ownerType,
            'userId' => $ownerType === 'user' ? $request->integer('user_id') : null,
            'beneficiaryId' => $ownerType === 'beneficiary' ? $request->integer('beneficiary_id') : null,
        ];
    }

    public function upsert(Request $request, MemberFinancialYearService $service)
    {
        $this->ensureAllowed($request, ['admin', 'treasurer']);

        $data = $request->validate([
            'owner_type' => ['required', 'in:user,beneficiary'],
            'user_id' => ['nullable', 'required_if:owner_type,user', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'required_if:owner_type,beneficiary', 'integer', 'exists:beneficiaries,id'],
            'financial_year_rule_id' => ['required', 'integer', 'exists:financial_year_rules,id'],
            'opening_balance' => ['required', 'numeric', 'min:0'],
            'commitment_amount' => ['required', 'numeric', 'min:0'],
        ]);

        ['userId' => $userId, 'beneficiaryId' => $beneficiaryId] = $this->resolveOwnerFromRequest($request);

        $mfy = $service->upsertSetup(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
            financialYearRuleId: (int) $data['financial_year_rule_id'],
            openingBalance: (float) $data['opening_balance'],
            commitmentAmount: (float) $data['commitment_amount'],
        );

        return response()->json([
            'message' => 'Saved.',
            'data' => $mfy,
        ]);
    }

    public function show(Request $request, MemberFinancialYearService $service)
    {
        $data = $request->validate([
            'owner_type' => ['required', 'in:user,beneficiary'],
            'user_id' => ['nullable', 'required_if:owner_type,user', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'required_if:owner_type,beneficiary', 'integer', 'exists:beneficiaries,id'],
            'financial_year_rule_id' => ['required', 'integer', 'exists:financial_year_rules,id'],
        ]);

        ['userId' => $userId, 'beneficiaryId' => $beneficiaryId] = $this->resolveOwnerFromRequest($request);

        $mfy = $service->getOrCreate(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
            financialYearRuleId: (int) $data['financial_year_rule_id']
        );

        return response()->json([
            'data' => $mfy,
        ]);
    }

    public function close(Request $request, MemberFinancialYearService $service)
    {
        $this->ensureAllowed($request, ['admin', 'treasurer']);

        $data = $request->validate([
            'owner_type' => ['required', 'in:user,beneficiary'],
            'user_id' => ['nullable', 'required_if:owner_type,user', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'required_if:owner_type,beneficiary', 'integer', 'exists:beneficiaries,id'],
            'financial_year_rule_id' => ['required', 'integer', 'exists:financial_year_rules,id'],
        ]);

        ['userId' => $userId, 'beneficiaryId' => $beneficiaryId] = $this->resolveOwnerFromRequest($request);

        $mfy = $service->close(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
            financialYearRuleId: (int) $data['financial_year_rule_id']
        );

        return response()->json([
            'message' => 'Closed.',
            'data' => $mfy,
        ]);
    }

    public function reopen(Request $request, MemberFinancialYearService $service)
    {
        $this->ensureAllowed($request, ['admin']);

        $data = $request->validate([
            'owner_type' => ['required', 'in:user,beneficiary'],
            'user_id' => ['nullable', 'required_if:owner_type,user', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'required_if:owner_type,beneficiary', 'integer', 'exists:beneficiaries,id'],
            'financial_year_rule_id' => ['required', 'integer', 'exists:financial_year_rules,id'],
        ]);

        ['userId' => $userId, 'beneficiaryId' => $beneficiaryId] = $this->resolveOwnerFromRequest($request);

        $mfy = $service->reopen(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
            financialYearRuleId: (int) $data['financial_year_rule_id']
        );

        return response()->json([
            'message' => 'Reopened.',
            'data' => $mfy,
        ]);
    }
}