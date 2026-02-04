<?php

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

class TopUpLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller will enforce role
    }

    public function rules(): array
    {
        return [
            'amount' => ['required','numeric','min:1'],
            'due_date' => ['required','date'],
        ];
    }
}
