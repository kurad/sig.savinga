<?php

namespace App\Http\Controllers;

use App\Http\Requests\Members\StoreMemberRequest;
use App\Http\Requests\Members\ToggleMemberStatusRequest;
use App\Http\Requests\Members\UpdateMemberRequest;
use App\Models\User;
use App\Services\MemberService;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    public function __construct(
        protected MemberService $memberService
    ) {}

    public function index(Request $request)
    {
        $query = User::query()->select('id','name','email','phone','role','is_active','created_at');

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

        return response()->json($query->orderBy('name')->paginate(15));
    }

    public function store(StoreMemberRequest $request)
    {
        $result = $this->memberService->create($request->validated());

        return response()->json([
            'message' => 'Member created successfully',
            'data' => $result['user'],
            'generated_password' => $result['plain_password'], // show once if auto generated
        ], 201);
    }

    public function show(User $user)
    {
        return response()->json($user->only(['id','name','email','phone','role','is_active','created_at']));
    }

    public function update(UpdateMemberRequest $request, User $user)
    {
        $updated = $this->memberService->update($user, $request->validated());

        return response()->json([
            'message' => 'Member updated successfully',
            'data' => $updated->only(['id','name','email','phone','role','is_active']),
        ]);
    }

    public function toggleStatus(ToggleMemberStatusRequest $request, User $user)
    {
        $updated = $this->memberService->setActive($user, (bool) $request->boolean('is_active'));

        return response()->json([
            'message' => 'Member status updated',
            'data' => $updated->only(['id','name','email','phone','role','is_active']),
        ]);
    }
}
