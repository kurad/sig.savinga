<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Services\StatementService;
use App\Http\Controllers\Controller;
use App\Services\StatementReportService;

class StatementController extends Controller
{
    public function __construct(
        protected StatementService $statementService,
        protected StatementReportService $statementReportService
    ) {}

    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 15);
        $perPage = max(5, min($perPage, 200)); // safety

        $data = $this->statementReportService->list(
            filters: $request->only(['user_id', 'type', 'from', 'to', 'q']),
            perPage: $perPage
        );

        return response()->json([
            'message' => 'Group statement',
            'data' => $data,
        ]);
    }

    public function show(Request $request, User $user)
    {
        try {
            $data = $this->statementService->memberStatement(
                viewer: $request->user(),
                member: $user,
                from: $request->query('from'),
                to: $request->query('to')
            );

            return response()->json($data);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Forbidden') {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
