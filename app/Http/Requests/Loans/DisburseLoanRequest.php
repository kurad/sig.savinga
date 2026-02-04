<?php

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

class DisburseLoanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['treasurer', 'admin'], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'principal' => ['required', 'numeric', 'min:1'],
            'due_date' => ['required', 'date'],

            'repayment_mode' => ['nullable', 'in:once,installment'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:60'],

            'guarantors' => ['nullable', 'array'],
            'guarantors.*.user_id' => ['required_with:guarantors', 'integer', 'exists:users,id'],
            'guarantors.*.amount' => ['required_with:guarantors', 'numeric', 'min:1'],
        ];
    }
}
