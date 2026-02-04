<?php

namespace App\Http\Controllers;

use App\Models\FinancialYearRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialYearRuleController extends Controller
{
    /**
     * List all rules (latest first)
     */
    public function index(Request $request)
    {
        $rules = FinancialYearRule::query()
            ->orderByDesc('is_active')
            ->orderByDesc('start_date')
            ->get();

        return response()->json([
            'data' => $rules,
        ]);
    }

    /**
     * Get active rule (used by DueDateService)
     */
    public function active(Request $request)
    {
        $rule = FinancialYearRule::query()
            ->where('is_active', true)
            ->first();

        if (!$rule) {
            return response()->json([
                'message' => 'No active financial year rule found.',
            ], 404);
        }

        return response()->json([
            'data' => $rule,
        ]);
    }

    /**
     * Store a new rule
     */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);

        return DB::transaction(function () use ($data) {
            // If request says active, deactivate others first
            if (!empty($data['is_active'])) {
                FinancialYearRule::query()->update(['is_active' => false]);
            }

            $rule = FinancialYearRule::create($data);

            return response()->json([
                'message' => 'Financial year rule created.',
                'data' => $rule,
            ], 201);
        });
    }

    /**
     * Update existing rule
     */
    public function update(Request $request, FinancialYearRule $financialYearRule)
    {
        $data = $this->validatePayload($request, $financialYearRule->id);

        return DB::transaction(function () use ($data, $financialYearRule) {
            if (!empty($data['is_active'])) {
                FinancialYearRule::query()
                    ->where('id', '!=', $financialYearRule->id)
                    ->update(['is_active' => false]);
            }

            $financialYearRule->update($data);

            return response()->json([
                'message' => 'Financial year rule updated.',
                'data' => $financialYearRule->fresh(),
            ]);
        });
    }

    /**
     * Activate a rule and deactivate all others
     */
    public function activate(Request $request, FinancialYearRule $financialYearRule)
    {
        return DB::transaction(function () use ($financialYearRule) {
            FinancialYearRule::query()->update(['is_active' => false]);

            $financialYearRule->update(['is_active' => true]);

            return response()->json([
                'message' => 'Financial year rule activated.',
                'data' => $financialYearRule->fresh(),
            ]);
        });
    }

    /**
     * Delete rule (prevent deleting active one)
     */
    public function destroy(Request $request, FinancialYearRule $financialYearRule)
    {
        if ($financialYearRule->is_active) {
            return response()->json([
                'message' => 'Cannot delete the active financial year rule.',
            ], 422);
        }

        $financialYearRule->delete();

        return response()->json([
            'message' => 'Financial year rule deleted.',
        ]);
    }

    /**
     * Validation shared
     */
    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $rules = [
            'year_key'    => ['required', 'string', 'max:20'],
            'start_date'  => ['required', 'date'],
            'end_date'    => ['required', 'date', 'after_or_equal:start_date'],
            'due_day'     => ['required', 'integer', 'min:1', 'max:31'],
            'grace_days'  => ['required', 'integer', 'min:0', 'max:31'],
            'is_active'   => ['sometimes', 'boolean'],
        ];

        // Optional: enforce unique year_key (recommended)
        // If you have a unique index, keep this too.
        // $unique = 'unique:financial_year_rules,year_key';
        // if ($ignoreId) $unique .= ',' . $ignoreId;
        // $rules['year_key'][] = $unique;

        return $request->validate($rules);
    }
}
