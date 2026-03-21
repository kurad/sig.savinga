<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ContributionCommitment;
use App\Models\SystemRule;
use App\Services\CommitmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CommitmentController extends Controller
{
    public function __construct(
        protected CommitmentService $commitmentService
    ) {}

    protected function commitmentRelations(): array
    {
        return [
            'user:id,name,email,phone',
            'beneficiary:id,guardian_user_id,name,relationship',
            'beneficiary.guardian:id,name,email,phone',
        ];
    }
    public function index(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'status' => ['nullable', 'in:active,expired'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = (int) ($data['per_page'] ?? 50);

        $query = ContributionCommitment::query()
            ->with($this->commitmentRelations())
            ->orderByDesc('cycle_start_period')
            ->orderByDesc('id');

        if (!empty($data['user_id'])) {
            $query->where('user_id', (int) $data['user_id']);
        }

        if (!empty($data['beneficiary_id'])) {
            $query->where('beneficiary_id', (int) $data['beneficiary_id']);
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return response()->json($query->paginate($perPage));
    }
    public function active(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'period' => ['required', 'date_format:Y-m'],
        ]);

        $commitment = $this->commitmentService->activeForPeriod(
            userId: (int) $data['user_id'],
            beneficiaryId: $data['beneficiary_id'] ?? null,
            periodKey: $data['period']
        );

        return response()->json([
            'data' => $commitment?->load($this->commitmentRelations()),
        ]);
    }
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'start_period' => ['required', 'date_format:Y-m'],
        ]);

        $rules = SystemRule::firstOrFail();

        $min = (float) ($rules->contribution_min_amount ?? 0);
        if ((float) $data['amount'] < $min) {
            throw ValidationException::withMessages([
                'amount' => ["Amount cannot be below minimum ({$min})."],
            ]);
        }

        $cycleMonths = (int) ($rules->contribution_cycle_months ?? 12);
        $anchor = (string) ($rules->cycle_anchor_period ?? $data['start_period']);

        [$cycleStart, $cycleEnd] = $this->commitmentService->cycleWindow(
            period: $data['start_period'],
            anchor: $anchor,
            cycleMonths: $cycleMonths
        );

        if ($data['start_period'] !== $cycleStart) {
            throw ValidationException::withMessages([
                'start_period' => ["Commitment can only start at cycle start ({$cycleStart})."],
            ]);
        }

        $commitment = $this->commitmentService->setForCycle(
            userId: (int) $data['user_id'],
            beneficiaryId: $data['beneficiary_id'] ?? null,
            amount: (float) $data['amount'],
            cycleStart: $cycleStart,
            cycleEnd: $cycleEnd,
            cycleMonths: $cycleMonths,
            createdBy: (int) $request->user()->id
        );

        return response()->json([
            'message' => 'Commitment saved successfully.',
            'data' => $commitment->load($this->commitmentRelations()),
        ], 201);
    }
    public function expire(ContributionCommitment $commitment)
    {
        if ($commitment->status === 'expired') {
            return response()->json([
                'message' => 'Commitment already expired.',
                'data' => $commitment->load($this->commitmentRelations()),
            ]);
        }

        $commitment->update([
            'status' => 'expired',
        ]);

        return response()->json([
            'message' => 'Commitment expired successfully.',
            'data' => $commitment->refresh()->load($this->commitmentRelations()),
        ]);
    }

    public function showByParticipant(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'period' => ['nullable', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
        ]);

        $q = ContributionCommitment::query()
            ->with($this->commitmentRelations())
            ->where('user_id', (int) $data['user_id']);

        if (array_key_exists('beneficiary_id', $data) && $data['beneficiary_id']) {
            $q->where('beneficiary_id', (int) $data['beneficiary_id']);
        } else {
            $q->whereNull('beneficiary_id');
        }

        if (!empty($data['period'])) {
            $q->where('cycle_start_period', '<=', $data['period'])
                ->where('cycle_end_period', '>=', $data['period']);
        }

        $item = $q->orderByDesc('cycle_start_period')
            ->orderByDesc('id')
            ->first();

        if (!$item) {
            return response()->json([
                'message' => 'Commitment not found for this participant.',
            ], 404);
        }

        return response()->json([
            'data' => $item,
        ]);
    }
    public function update(Request $request, ContributionCommitment $commitment)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cycle_start_period' => ['required', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
            'cycle_end_period' => ['required', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
            'cycle_months' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:active,expired'],
            'activated_at' => ['nullable', 'date'],
        ]);

        if ($data['cycle_end_period'] < $data['cycle_start_period']) {
            throw ValidationException::withMessages([
                'cycle_end_period' => ['cycle_end_period cannot be before cycle_start_period.'],
            ]);
        }

        $rules = SystemRule::first();
        $min = (float) ($rules->contribution_min_amount ?? 0);

        if ((float) $data['amount'] < $min) {
            throw ValidationException::withMessages([
                'amount' => ["Amount cannot be below minimum ({$min})."],
            ]);
        }

        $updated = $this->commitmentService->updateCommitment(
            commitment: $commitment,
            userId: (int) $data['user_id'],
            beneficiaryId: $data['beneficiary_id'] ?? null,
            amount: (float) $data['amount'],
            cycleStart: $data['cycle_start_period'],
            cycleEnd: $data['cycle_end_period'],
            cycleMonths: (int) $data['cycle_months'],
            status: $data['status'],
            activatedAt: $data['activated_at'] ?? null
        );

        return response()->json([
            'message' => 'Commitment updated successfully.',
            'data' => $updated->load($this->commitmentRelations()),
        ]);
    }
}
