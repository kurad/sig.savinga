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

            // manual registration = new member
            'source' => 'manual',
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
}