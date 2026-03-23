<?php

namespace App\Http\Requests\Incomes;

use Illuminate\Foundation\Http\FormRequest;

class StoreIncomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'income_date' => ['required', 'date'],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->filled('user_id') && $this->filled('beneficiary_id')) {
                $validator->errors()->add(
                    'user_id',
                    'Income cannot be assigned to both member and beneficiary at the same time.'
                );
            }
        });
    }
}