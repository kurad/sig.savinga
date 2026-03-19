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
            'owner_type' => ['required', 'in:user,beneficiary'],

            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                'required_if:owner_type,user',
                'prohibited_if:owner_type,beneficiary',
            ],

            'beneficiary_id' => [
                'nullable',
                'integer',
                'exists:beneficiaries,id',
                'required_if:owner_type,beneficiary',
                'prohibited_if:owner_type,user',
            ],

            'amount' => ['required', 'numeric', 'min:0.01'],
            'period' => ['required', 'date_format:Y-m'],
            'expected_date' => ['nullable', 'date_format:Y-m-d'],
            'paid_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'owner_type.required' => 'owner_type is required.',
            'owner_type.in' => 'owner_type must be either user or beneficiary.',

            'user_id.required_if' => 'Member is required when owner_type is user.',
            'user_id.exists' => 'Member not found.',
            'user_id.prohibited_if' => 'user_id must not be sent when owner_type is beneficiary.',

            'beneficiary_id.required_if' => 'Beneficiary is required when owner_type is beneficiary.',
            'beneficiary_id.exists' => 'Beneficiary not found.',
            'beneficiary_id.prohibited_if' => 'beneficiary_id must not be sent when owner_type is user.',

            'period.date_format' => 'period must be in Y-m format (e.g. 2026-01).',
            'expected_date.date_format' => 'expected_date must be in Y-m-d format.',
            'paid_date.date_format' => 'paid_date must be in Y-m-d format.',
        ];
    }
}