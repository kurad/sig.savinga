<?php

namespace App\Services;

use App\Models\Beneficiary;

class BeneficiaryService
{
    public function create(array $data): Beneficiary
    {
        return Beneficiary::create([
            'guardian_user_id' => $data['guardian_user_id'],
            'name' => $data['name'],
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'relationship' => $data['relationship'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'joined_at' => $data['joined_at'] ?? now()->toDateString(),
            'registration_fee_required' => $data['registration_fee_required'] ?? true,
            'registration_fee_amount' => $data['registration_fee_amount'] ?? 10000,
            'registration_fee_status' => ($data['registration_fee_required'] ?? true)
                ? 'pending'
                : 'not_applicable',
            'registration_note' => $data['registration_note'] ?? null,
        ]);
    }

    public function update(Beneficiary $beneficiary, array $data): Beneficiary
    {
        $beneficiary->update($data);
        return $beneficiary->fresh();
    }

    public function setActive(Beneficiary $beneficiary, bool $isActive): Beneficiary
    {
        $beneficiary->update(['is_active' => $isActive]);
        return $beneficiary->fresh();
    }
}