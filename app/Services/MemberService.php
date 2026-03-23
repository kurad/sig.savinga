<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class MemberService
{
    public function __construct(
        protected IncomeService $incomeService
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
            'plain_password' => isset($data['password']) ? null : $password,
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

    public function recordRegistrationFee(User $user, int $recordedBy, ?string $incomeDate = null): User
    {
        if (!$user->registration_fee_required) {
            throw new InvalidArgumentException('Registration fee is not applicable for this member.');
        }

        if ($user->registration_fee_status === 'paid') {
            throw new InvalidArgumentException('Registration fee has already been paid.');
        }

        if ($user->registration_fee_status === 'waived') {
            throw new InvalidArgumentException('Registration fee was waived for this member.');
        }

        DB::transaction(function () use ($user, $recordedBy, $incomeDate) {
            $this->incomeService->record(
                amount: (float) $user->registration_fee_amount,
                incomeDate: $incomeDate ?? now()->toDateString(),
                recordedBy: $recordedBy,
                category: 'Registration Fee',
                description: "Registration fee for new member: {$user->name}"
            );

            $user->update([
                'registration_fee_status' => 'paid',
                'registration_paid_at' => now(),
                'registration_recorded_by' => $recordedBy,
            ]);
        });

        return $user->fresh();
    }

    public function waiveRegistrationFee(User $user, int $recordedBy, ?string $note = null): User
    {
        if (!$user->registration_fee_required) {
            throw new InvalidArgumentException('Registration fee is not applicable for this member.');
        }

        if ($user->registration_fee_status === 'paid') {
            throw new InvalidArgumentException('Cannot waive a registration fee that is already paid.');
        }

        $user->update([
            'registration_fee_status' => 'waived',
            'registration_note' => $note ?? "Waived by user ID {$recordedBy}",
            'registration_recorded_by' => $recordedBy,
        ]);

        return $user->fresh();
    }

    public function importFromExcel(array $rows, int $recordedBy): array
    {
        $created = [];
        $skipped = [];
        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2; // assuming row 1 is heading

                try {
                    $name  = trim((string) ($row['name'] ?? ''));
                    $email = trim((string) ($row['email'] ?? ''));
                    $phone = trim((string) ($row['phone'] ?? ''));
                    $role  = trim((string) ($row['role'] ?? 'member'));
                    $joinedAt = !empty($row['joined_at']) ? $row['joined_at'] : now()->toDateString();
                    $registrationFeeAmount = !empty($row['registration_fee_amount'])
                        ? (float) $row['registration_fee_amount']
                        : 10000;
                    $registrationNote = $row['registration_note'] ?? null;
                    $password = !empty($row['password']) ? (string) $row['password'] : null;

                    if ($name === '') {
                        $skipped[] = [
                            'row' => $rowNumber,
                            'reason' => 'Name is required.',
                        ];
                        continue;
                    }

                    if ($email !== '' && User::where('email', $email)->exists()) {
                        $skipped[] = [
                            'row' => $rowNumber,
                            'reason' => "Email already exists: {$email}",
                        ];
                        continue;
                    }

                    if ($phone !== '' && User::where('phone', $phone)->exists()) {
                        $skipped[] = [
                            'row' => $rowNumber,
                            'reason' => "Phone already exists: {$phone}",
                        ];
                        continue;
                    }

                    $result = $this->create([
                        'name' => $name,
                        'email' => $email ?: null,
                        'phone' => $phone ?: null,
                        'role' => in_array($role, ['admin', 'treasurer', 'member'], true) ? $role : 'member',
                        'joined_at' => $joinedAt,
                        'registration_fee_amount' => $registrationFeeAmount,
                        'registration_note' => $registrationNote,
                        'password' => $password,
                    ], $recordedBy);

                    $created[] = [
                        'row' => $rowNumber,
                        'name' => $result['user']->name,
                        'email' => $result['user']->email,
                        'phone' => $result['user']->phone,
                        'plain_password' => $result['plain_password'],
                    ];
                } catch (\Throwable $e) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            DB::commit();

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
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}