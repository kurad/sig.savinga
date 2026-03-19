<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Beneficiary;
use App\Services\BeneficiaryService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class BeneficiaryController extends Controller
{
    public function __construct(
        protected BeneficiaryService $beneficiaryService
    ) {}

    /**
     * Display a listing of beneficiaries.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Beneficiary::query();

        if ($request->filled('guardian_user_id')) {
            $query->where('guardian_user_id', $request->guardian_user_id);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $beneficiaries = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json($beneficiaries);
    }

    /**
     * Store a newly created beneficiary.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'guardian_user_id' => ['required', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'relationship' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'joined_at' => ['nullable', 'date'],
            'registration_fee_required' => ['nullable', 'boolean'],
            'registration_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'registration_note' => ['nullable', 'string'],
        ]);

        $beneficiary = $this->beneficiaryService->create($validated);

        return response()->json([
            'message' => 'Beneficiary created successfully.',
            'data' => $beneficiary
        ], 201);
    }

    /**
     * Display the specified beneficiary.
     */
    public function show(Beneficiary $beneficiary): JsonResponse
    {
        return response()->json([
            'data' => $beneficiary
        ]);
    }

    /**
     * Update the specified beneficiary.
     */
    public function update(Request $request, Beneficiary $beneficiary): JsonResponse
    {
        $validated = $request->validate([
            'guardian_user_id' => ['sometimes', 'exists:users,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'relationship' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'joined_at' => ['nullable', 'date'],
            'registration_fee_required' => ['nullable', 'boolean'],
            'registration_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'registration_fee_status' => [
                'nullable',
                Rule::in(['pending', 'paid', 'waived', 'not_applicable'])
            ],
            'registration_note' => ['nullable', 'string'],
        ]);

        $updatedBeneficiary = $this->beneficiaryService->update($beneficiary, $validated);

        return response()->json([
            'message' => 'Beneficiary updated successfully.',
            'data' => $updatedBeneficiary
        ]);
    }

    /**
     * Activate or deactivate a beneficiary.
     */
    public function setActive(Request $request, Beneficiary $beneficiary): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $updatedBeneficiary = $this->beneficiaryService->setActive(
            $beneficiary,
            $validated['is_active']
        );

        return response()->json([
            'message' => $validated['is_active']
                ? 'Beneficiary activated successfully.'
                : 'Beneficiary deactivated successfully.',
            'data' => $updatedBeneficiary
        ]);
    }
}