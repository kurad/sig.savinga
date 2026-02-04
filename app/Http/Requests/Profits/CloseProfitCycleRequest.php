<?php

namespace App\Http\Requests\Profits;

use Illuminate\Foundation\Http\FormRequest;

class CloseProfitCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'treasurer'], true);
    }

    public function rules(): array
    {
        return [
            'extra_other_income' => ['nullable', 'numeric', 'min:0'],
            'extra_expenses'     => ['nullable', 'numeric', 'min:0'],
            'distribution_type'  => ['nullable', 'in:credit,cash'], // default credit
        ];
    }
}
