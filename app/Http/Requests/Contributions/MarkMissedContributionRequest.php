<?php

namespace App\Http\Requests\Contributions;

use Illuminate\Foundation\Http\FormRequest;

class MarkMissedContributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['treasurer', 'admin'], true);
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],

            'period' => ['required_without:expected_date', 'nullable', 'date_format:Y-m'],
            'expected_date' => ['required_without:period', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'Member is required.',
            'user_id.integer' => 'Member ID must be a valid number.',
            'user_id.exists' => 'Member not found.',

            'beneficiary_id.integer' => 'Beneficiary ID must be a valid number.',
            'beneficiary_id.exists' => 'Beneficiary not found.',

            'period.required_without' => 'Period is required when expected_date is not provided.',
            'period.date_format' => 'Period must be in Y-m format (e.g. 2026-03).',

            'expected_date.required_without' => 'Expected date is required when period is not provided.',
            'expected_date.date_format' => 'Expected date must be in Y-m-d format.',
        ];
    }
}