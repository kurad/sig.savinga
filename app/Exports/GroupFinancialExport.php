<?php

namespace App\Exports;

use App\Models\User;
use App\Models\Contribution;
use App\Services\OpeningBalanceService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class GroupFinancialExport implements WithMultipleSheets
{
    public function __construct(
        protected OpeningBalanceService $openingService
    ) {}

    public function sheets(): array
    {
        return [
            new MembersSummarySheet($this->openingService),
            new GroupSummarySheet($this->openingService),
        ];
    }
}