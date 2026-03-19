<?php

namespace App\Http\Requests\payroll;

use Illuminate\Foundation\Http\FormRequest;

class PayrollCommitRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'period' => ['required','date_format:Y-m'],
            'paid_date' => ['required','date_format:Y-m-d'],
            'expected_date' => ['nullable','date_format:Y-m-d'],
            'file' => ['required','file','mimes:csv,txt'],
        ];
    }
}