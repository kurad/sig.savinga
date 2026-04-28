<?php

namespace App\Exports;

use App\Models\User;
use App\Models\Contribution;
use App\Services\OpeningBalanceService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class MembersSummarySheet implements FromCollection, WithHeadings
{
    protected OpeningBalanceService $openingService;

    public function __construct(OpeningBalanceService $openingService)
    {
        $this->openingService = $openingService;
    }

    public function collection()
    {
        return User::where('role', 'member')->get()->map(function ($user) {

            // ✅ Opening balance (includes adjustments)
            $opening = $this->openingService
                ->openingBalanceForOwner($user->id);

            // ✅ Contributions total
            $contributions = Contribution::where('user_id', $user->id)
                ->sum('amount');

            return [
                'name' => $user->name,
                'opening_balance' => $opening,
                'contributions' => $contributions,
                'total_balance' => $opening + $contributions,
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Member Name',
            'Opening Balance',
            'Contributions',
            'Total Balance',
        ];
    }
}