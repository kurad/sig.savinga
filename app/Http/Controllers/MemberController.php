<?php

namespace App\Http\Controllers;

use App\Http\Requests\Members\StoreMemberRequest;
use App\Http\Requests\Members\ToggleMemberStatusRequest;
use App\Http\Requests\Members\UpdateMemberRequest;
use App\Imports\MembersImport;
use App\Models\ContributionCommitment;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Services\MemberService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class MemberController extends Controller
{
    public function __construct(
        protected MemberService $memberService
    ) {}

    public function index(Request $request)
    {
        $request->validate([
            'as_of_period' => ['nullable', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
        ]);

        $period = $request->query('as_of_period');

        $query = User::query()->select(
            'id',
            'name',
            'email',
            'phone',
            'role',
            'is_active',
            'created_at',
            'joined_at',
            'registration_fee_required',
            'registration_fee_amount',
            'registration_fee_status',
            'registration_paid_at',
            'registration_recorded_by',
            'registration_note',
            'source'
        );

        $query->addSelect([
            'opening_balance_any' => OpeningBalance::query()
                ->whereColumn('opening_balances.user_id', 'users.id')
                ->selectRaw('1')
                ->limit(1),
        ]);

        $query->addSelect([
            'opening_balance_latest_period' => OpeningBalance::query()
                ->whereColumn('opening_balances.user_id', 'users.id')
                ->select('as_of_period')
                ->orderByDesc('as_of_period')
                ->limit(1),
        ]);

        $query->addSelect([
            'commitment_any' => ContributionCommitment::query()
                ->whereColumn('contribution_commitments.user_id', 'users.id')
                ->selectRaw('1')
                ->limit(1),
        ]);

        if ($period) {
            $query->addSelect([
                'commitment_active' => ContributionCommitment::query()
                    ->whereColumn('contribution_commitments.user_id', 'users.id')
                    ->where('status', 'active')
                    ->where('cycle_start_period', '<=', $period)
                    ->where('cycle_end_period', '>=', $period)
                    ->selectRaw('1')
                    ->limit(1),

                'commitment_active_start_period' => ContributionCommitment::query()
                    ->whereColumn('contribution_commitments.user_id', 'users.id')
                    ->where('status', 'active')
                    ->where('cycle_start_period', '<=', $period)
                    ->where('cycle_end_period', '>=', $period)
                    ->select('cycle_start_period')
                    ->orderByDesc('cycle_start_period')
                    ->limit(1),

                'commitment_active_end_period' => ContributionCommitment::query()
                    ->whereColumn('contribution_commitments.user_id', 'users.id')
                    ->where('status', 'active')
                    ->where('cycle_start_period', '<=', $period)
                    ->where('cycle_end_period', '>=', $period)
                    ->select('cycle_end_period')
                    ->orderByDesc('cycle_start_period')
                    ->limit(1),
            ]);
        }

        if ($request->filled('role')) {
            $query->where('role', $request->query('role'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('q')) {
            $q = $request->query('q');
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        $query->with([
            'beneficiaries' => function ($bq) use ($period) {
                $bq->select(
                    'id',
                    'guardian_user_id',
                    'name',
                    'date_of_birth',
                    'relationship',
                    'is_active',
                    'joined_at',
                    'created_at',
                    'registration_fee_required',
                    'registration_fee_amount',
                    'registration_fee_status',
                    'registration_paid_at',
                    'registration_recorded_by',
                    'registration_note'
                );

                $bq->addSelect([
                    'opening_balance_any' => OpeningBalance::query()
                        ->whereColumn('opening_balances.beneficiary_id', 'beneficiaries.id')
                        ->selectRaw('1')
                        ->limit(1),
                ]);

                $bq->addSelect([
                    'opening_balance_latest_period' => OpeningBalance::query()
                        ->whereColumn('opening_balances.beneficiary_id', 'beneficiaries.id')
                        ->select('as_of_period')
                        ->orderByDesc('as_of_period')
                        ->limit(1),
                ]);

                $bq->addSelect([
                    'commitment_any' => ContributionCommitment::query()
                        ->whereColumn('contribution_commitments.beneficiary_id', 'beneficiaries.id')
                        ->selectRaw('1')
                        ->limit(1),
                ]);

                if ($period) {
                    $bq->addSelect([
                        'commitment_active' => ContributionCommitment::query()
                            ->whereColumn('contribution_commitments.beneficiary_id', 'beneficiaries.id')
                            ->where('status', 'active')
                            ->where('cycle_start_period', '<=', $period)
                            ->where('cycle_end_period', '>=', $period)
                            ->selectRaw('1')
                            ->limit(1),

                        'commitment_active_start_period' => ContributionCommitment::query()
                            ->whereColumn('contribution_commitments.beneficiary_id', 'beneficiaries.id')
                            ->where('status', 'active')
                            ->where('cycle_start_period', '<=', $period)
                            ->where('cycle_end_period', '>=', $period)
                            ->select('cycle_start_period')
                            ->orderByDesc('cycle_start_period')
                            ->limit(1),

                        'commitment_active_end_period' => ContributionCommitment::query()
                            ->whereColumn('contribution_commitments.beneficiary_id', 'beneficiaries.id')
                            ->where('status', 'active')
                            ->where('cycle_start_period', '<=', $period)
                            ->where('cycle_end_period', '>=', $period)
                            ->select('cycle_end_period')
                            ->orderByDesc('cycle_start_period')
                            ->limit(1),
                    ]);
                }

                $bq->orderBy('name');
            }
        ]);

        $page = $query->orderBy('name')->paginate(15);

        $page->getCollection()->transform(function ($u) use ($period) {
            $u->opening_balance_any = (bool) ($u->opening_balance_any ?? false);
            $u->commitment_any = (bool) ($u->commitment_any ?? false);
            $u->commitment_active = $period ? (bool) ($u->commitment_active ?? false) : false;

            $u->beneficiaries = $u->beneficiaries->map(function ($b) use ($period) {
                $b->opening_balance_any = (bool) ($b->opening_balance_any ?? false);
                $b->commitment_any = (bool) ($b->commitment_any ?? false);
                $b->commitment_active = $period ? (bool) ($b->commitment_active ?? false) : false;

                return $b;
            })->values();

            return $u;
        });

        return response()->json($page);
    }

    public function store(StoreMemberRequest $request)
    {
        $result = $this->memberService->create($request->validated(), $request->user()->id);

        return response()->json([
            'message' => 'Member created successfully',
            'data' => $result['user'],
            'generated_password' => $result['plain_password'],
        ], 201);
    }

    public function show(User $user)
    {
        return response()->json(
            $user->only([
                'id',
                'name',
                'email',
                'phone',
                'role',
                'is_active',
                'created_at',
                'joined_at',
                'registration_fee_required',
                'registration_fee_amount',
                'registration_fee_status',
                'registration_paid_at',
                'registration_recorded_by',
                'registration_note',
                'source'
            ])
        );
    }

    public function update(UpdateMemberRequest $request, User $user)
    {
        $updated = $this->memberService->update($user, $request->validated());

        return response()->json([
            'message' => 'Member updated successfully',
            'data' => $updated->only([
                'id',
                'name',
                'email',
                'phone',
                'role',
                'is_active',
                'joined_at',
                'registration_fee_required',
                'registration_fee_amount',
                'registration_fee_status',
                'registration_paid_at',
                'registration_recorded_by',
                'registration_note',
                'source'
            ]),
        ]);
    }

    public function toggleStatus(ToggleMemberStatusRequest $request, User $user)
    {
        $updated = $this->memberService->setActive($user, (bool) $request->boolean('is_active'));

        return response()->json([
            'message' => 'Member status updated',
            'data' => $updated->only(['id', 'name', 'email', 'phone', 'role', 'is_active']),
        ]);
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        $sheets = Excel::toArray(new MembersImport, $request->file('file'));
        $rows = $sheets[0] ?? [];

        if (empty($rows)) {
            return response()->json([
                'message' => 'The uploaded file contains no data.',
            ], 422);
        }

        $result = $this->memberService->importFromExcel(
            rows: $rows,
            recordedBy: $request->user()->id
        );

        return response()->json([
            'message' => 'Members imported successfully.',
            'data' => $result,
        ]);
    }
}