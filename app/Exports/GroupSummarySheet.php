<?php

namespace App\Exports;

use App\Models\User;
use App\Models\Contribution;
use App\Services\OpeningBalanceService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class GroupSummarySheet implements FromCollection, WithHeadings
{
    public function __construct(
        protected OpeningBalanceService $openingService
    ) {}

    public function collection()
    {
        $members = User::where('role', 'member')->get();

        $totalOpening = 0;
        $totalContributions = 0;

        foreach ($members as $user) {
            $totalOpening += $this->openingService
                ->openingBalanceForOwner($user->id);

            $totalContributions += Contribution::where('user_id', $user->id)
                ->sum('amount');
        }

        return collect([
            ['Total Opening', $totalOpening],
            ['Total Contributions', $totalContributions],
            ['Total Balance', $totalOpening + $totalContributions],
        ]);
    }

    public function headings(): array
    {
        return ['Metric', 'Amount'];
    }
}