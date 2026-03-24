<?php

namespace App\Services;

use App\Models\FinancialYearRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MemberService
{
    public function __construct(
        protected OpeningBalanceService $openingBalanceService,
        protected CommitmentService $commitmentService
    ) {}

    public function create(array $data, int $recordedBy): array
    {
        $password = $data['password'] ?? Str::random(10);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'] ?? 'member',
            'is_active' => true,
            'password' => Hash::make($password),
            'joined_at' => $data['joined_at'] ?? now()->toDateString(),

            'source' => $data['source'] ?? 'manual',
            'registration_fee_required' => true,
            'registration_fee_amount' => $data['registration_fee_amount'] ?? 10000,
            'registration_fee_status' => 'pending',
            'registration_paid_at' => null,
            'registration_recorded_by' => null,
            'registration_note' => $data['registration_note'] ?? null,
        ]);

        return [
            'user' => $user,
            'plain_password' => array_key_exists('password', $data) && $data['password']
    ? null
    : $password,
        ];
    }

    public function update(User $user, array $data): User
    {
        if (array_key_exists('password', $data) && $data['password']) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return $user;
    }

    public function setActive(User $user, bool $isActive): User
    {
        $user->update(['is_active' => $isActive]);
        return $user;
    }

    public function importFromExcel(array $rows, int $recordedBy): array
    {
        $created = [];
        $skipped = [];
        $errors = [];

        $fy = $this->resolveActiveFinancialYear();
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // row 1 is headings

            try {
                DB::transaction(function () use ($row, $rowNumber, $recordedBy, $fy, &$created, &$skipped) {
                    $name = trim((string) ($row['name'] ?? ''));
                    $email = trim((string) ($row['email'] ?? ''));
                    $phone = trim((string) ($row['phone'] ?? ''));
                    $role = trim((string) ($row['role'] ?? 'member'));
                    $joinedAt = !empty($row['joined_at'])
                        ? Carbon::parse($row['joined_at'])->toDateString()
                        : now()->toDateString();

                    $openingBalanceAmount = isset($row['opening_balance_amount']) && $row['opening_balance_amount'] !== ''
                        ? (float) $row['opening_balance_amount']
                        : null;

                    $commitmentAmount = isset($row['commitment_amount']) && $row['commitment_amount'] !== ''
                        ? (float) $row['commitment_amount']
                        : null;

                    if ($name === '') {
                        $skipped[] = [
                            'row' => $rowNumber,
                            'reason' => 'Name is required.',
                        ];
                        return;
                    }

                    if ($email !== '' && User::where('email', $email)->exists()) {
                        $skipped[] = [
                            'row' => $rowNumber,
                            'reason' => "Email already exists: {$email}",
                        ];
                        return;
                    }

                    if ($phone !== '' && User::where('phone', $phone)->exists()) {
                        $skipped[] = [
                            'row' => $rowNumber,
                            'reason' => "Phone already exists: {$phone}",
                        ];
                        return;
                    }

                    if ($openingBalanceAmount < 0) {
                        throw new InvalidArgumentException('Opening balance amount cannot be negative.');
                    }

                    if ($commitmentAmount < 0) {
                        throw new InvalidArgumentException('Commitment amount cannot be negative.');
                    }

                    $result = $this->create([
                        'name' => $name,
                        'email' => $email ?: null,
                        'phone' => $phone ?: null,
                        'role' => in_array($role, ['admin', 'treasurer', 'member'], true)
                            ? $role
                            : 'member',
                        'joined_at' => $joinedAt,
                        'source' => 'excel',
                    ], $recordedBy);

                    $user = $result['user'];

                    if ($openingBalanceAmount > 0) {
                        $this->openingBalanceService->setOpeningBalance([
                            'user_id' => $user->id,
                            'as_of_period' => 'FA' . $fy->year_key,
                            'amount' => $openingBalanceAmount,
                            'note' => 'Imported from Excel',
                        ], $recordedBy);
                    }

                    if ($commitmentAmount > 0) {
                       
                        $this->commitmentService->setForCycle(
                            userId: $user->id,
                            beneficiaryId: null,
                            amount: $commitmentAmount,
                            cycleStart: $fy->start_period,
                            cycleEnd: $fy->end_period,
                            cycleMonths: $fy->cycle_months,
                            createdBy: $recordedBy
                        );
                    }

                    $created[] = [
                        'row' => $rowNumber,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'plain_password' => $result['plain_password'],
                        'opening_balance_imported' => $openingBalanceAmount > 0,
                        'commitment_imported' => $commitmentAmount > 0,
                        'opening_balance_amount' => $openingBalanceAmount,
                        'commitment_amount' => $commitmentAmount,
                    ];
                });
            } catch (\Throwable $e) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'message' => 'Import completed.',
            'summary' => [
                'total_rows' => count($rows),
                'created_count' => count($created),
                'skipped_count' => count($skipped),
                'error_count' => count($errors),
            ],
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    protected function resolveActiveFinancialYear(): object
    {
        $fy = FinancialYearRule::query()
            ->where('is_active', true)
            ->first();

        if (!$fy) {
            throw new InvalidArgumentException('No active financial year found.');
        }

        $start = Carbon::parse($fy->start_date)->startOfMonth();
        $end = Carbon::parse($fy->end_date)->startOfMonth();

        return (object) [
            'id' => $fy->id,
            'year_key' => $fy->year_key,
            'start_period' => $start->format('Y-m'),
            'end_period' => $end->format('Y-m'),
            'cycle_months' => $start->diffInMonths($end) + 1,
        ];
    }
}
