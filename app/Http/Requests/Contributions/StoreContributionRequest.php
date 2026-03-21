<?php

namespace App\Http\Requests\Contributions;

use Illuminate\Foundation\Http\FormRequest;

class StoreContributionRequest extends FormRequest
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

            'amount' => ['required', 'numeric', 'min:0.01'],
            'period' => ['required', 'date_format:Y-m'],
            'expected_date' => ['nullable', 'date_format:Y-m-d'],
            'paid_date' => ['required', 'date_format:Y-m-d'],
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

            'amount.required' => 'Amount is required.',
            'amount.numeric' => 'Amount must be numeric.',
            'amount.min' => 'Amount must be greater than 0.',

            'period.required' => 'Period is required.',
            'period.date_format' => 'Period must be in Y-m format (e.g. 2026-01).',

            'expected_date.date_format' => 'Expected date must be in Y-m-d format.',
            'paid_date.required' => 'Paid date is required.',
            'paid_date.date_format' => 'Paid date must be in Y-m-d format.',
        ];
    }
}