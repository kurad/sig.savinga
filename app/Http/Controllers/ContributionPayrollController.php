<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Services\PayrollContributionService;
use App\Http\Requests\payroll\PayrollPreviewRequest;
use App\Http\Requests\payroll\PayrollCommitRequest;


class ContributionPayrollController extends Controller
{
    public function __construct(
        protected PayrollContributionService $payrollService
    ) {}

    public function preview(PayrollPreviewRequest $request)
    {
        $periodKey = Carbon::createFromFormat('Y-m', $request->input('period'))->format('Y-m');
        $paidDate  = $request->input('paid_date');

        $rows = $this->payrollService->parseCsv($request->file('file'));

        $data = $this->payrollService->preview(
            periodKey: $periodKey,
            paidDate: $paidDate,
            rows: $rows,
            viewerId: (int) $request->user()->id
        );

        return response()->json($data);
    }

    public function commit(PayrollCommitRequest $request)
    {
        $periodKey = Carbon::createFromFormat('Y-m', $request->input('period'))->format('Y-m');
        $paidDate  = $request->input('paid_date');

        $rows = $this->payrollService->parseCsv($request->file('file'));

        $data = $this->payrollService->commit(
            periodKey: $periodKey,
            paidDate: $paidDate,
            expectedDate: $request->input('expected_date'), // optional
            rows: $rows,
            recordedBy: (int) $request->user()->id
        );

        return response()->json([
            'message' => 'Payroll processing completed.',
            'data' => $data,
        ]);
    }
}