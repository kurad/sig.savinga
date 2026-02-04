<?php

namespace App\Services;

use App\Models\ProfitCycle;

class ProfitReportService
{
    public function list(int $perPage = 15)
    {
        return ProfitCycle::query()
            ->orderByDesc('start_date')
            ->paginate($perPage);
    }

    public function show(int $cycleId)
    {
        return ProfitCycle::with([
            'distributions.user:id,name,email,phone'
        ])->findOrFail($cycleId);
    }
}
