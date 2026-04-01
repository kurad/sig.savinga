<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Beneficiary;
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

    /**
     * Resolve owner user_id.
     * - If user_id is provided, use it.
     * - If beneficiary_id is provided and user_id is missing, derive user_id from guardian_user_id.
     */
    protected function resolveOwnerUserId(?int $userId, ?int $beneficiaryId): int
    {
        if (!empty($userId)) {
            return (int) $userId;
        }

        if (!empty($beneficiaryId)) {
            $beneficiary = Beneficiary::findOrFail($beneficiaryId);

            if (empty($beneficiary->guardian_user_id)) {
                throw ValidationException::withMessages([
                    'beneficiary_id' => ['Selected beneficiary has no guardian user linked.'],
                ]);
            }

            return (int) $beneficiary->guardian_user_id;
        }

        throw ValidationException::withMessages([
            'participant' => ['Either user_id or beneficiary_id is required.'],
        ]);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'participant_type' => ['nullable', 'in:user,beneficiary'],
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

        if (!empty($data['participant_type'])) {
            if ($data['participant_type'] === 'beneficiary') {
                $query->whereNotNull('beneficiary_id');
            } else {
                $query->whereNull('beneficiary_id');
            }
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return response()->json($query->paginate($perPage));
    }

    public function active(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'period' => ['required', 'date_format:Y-m'],
        ]);

        if (empty($data['user_id']) && empty($data['beneficiary_id'])) {
            throw ValidationException::withMessages([
                'participant' => ['Either user_id or beneficiary_id is required.'],
            ]);
        }

        if (!empty($data['user_id']) && !empty($data['beneficiary_id'])) {
            // optional: only allow this if you intentionally support it
            $beneficiary = Beneficiary::findOrFail((int) $data['beneficiary_id']);

            if ((int) $beneficiary->guardian_user_id !== (int) $data['user_id']) {
                throw ValidationException::withMessages([
                    'beneficiary_id' => ['Selected beneficiary does not belong to the provided user_id.'],
                ]);
            }
        }

        $userId = $this->resolveOwnerUserId(
            $data['user_id'] ?? null,
            $data['beneficiary_id'] ?? null
        );

        $commitment = $this->commitmentService->activeForPeriod(
            userId: $userId,
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
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cycle_start_period' => ['required', 'date_format:Y-m'],
        ]);

        $userId = $this->resolveOwnerUserId(
            $data['user_id'] ?? null,
            $data['beneficiary_id'] ?? null
        );

        $rules = SystemRule::firstOrFail();

        $min = (float) ($rules->contribution_min_amount ?? 0);
        if ((float) $data['amount'] < $min) {
            throw ValidationException::withMessages([
                'amount' => ["Amount cannot be below minimum ({$min})."],
            ]);
        }

        $cycleMonths = (int) ($rules->contribution_cycle_months ?? 12);
        $anchor = (string) ($rules->cycle_anchor_period ?? $data['cycle_start_period']);

        [$cycleStart, $cycleEnd] = $this->commitmentService->cycleWindow(
            period: $data['cycle_start_period'],
            anchor: $anchor,
            cycleMonths: $cycleMonths
        );

        if ($data['cycle_start_period'] !== $cycleStart) {
            throw ValidationException::withMessages([
                'cycle_start_period' => ["Commitment can only start at cycle start ({$cycleStart})."],
            ]);
        }

        $commitment = $this->commitmentService->setForCycle(
            userId: $userId,
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
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'period' => ['nullable', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
        ]);

        $userId = $this->resolveOwnerUserId(
            $data['user_id'] ?? null,
            $data['beneficiary_id'] ?? null
        );

        $q = ContributionCommitment::query()
            ->with($this->commitmentRelations())
            ->where('user_id', $userId);

        if (!empty($data['beneficiary_id'])) {
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
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cycle_start_period' => ['nullable', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
        ]);

        $userId = $this->resolveOwnerUserId(
            $data['user_id'] ?? null,
            $data['beneficiary_id'] ?? null
        );

        $rules = SystemRule::firstOrFail();
        $min = (float) ($rules->contribution_min_amount ?? 0);

        if ((float) $data['amount'] < $min) {
            throw ValidationException::withMessages([
                'amount' => ["Amount cannot be below minimum ({$min})."],
            ]);
        }

        $updated = $this->commitmentService->updateAmountOrStartNewCycle(
            commitment: $commitment,
            userId: $userId,
            beneficiaryId: $data['beneficiary_id'] ?? null,
            amount: (float) $data['amount'],
            requestedStartPeriod: $data['cycle_start_period'] ?? null,
            createdBy: (int) $request->user()->id
        );

        return response()->json([
            'message' => $updated['message'],
            'data' => $updated['data']->load($this->commitmentRelations()),
        ]);
    }
}
