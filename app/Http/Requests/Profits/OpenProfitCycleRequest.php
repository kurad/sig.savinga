<?php

namespace App\Http\Requests\Profits;

use Illuminate\Foundation\Http\FormRequest;

class OpenProfitCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'treasurer'], true);
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date'   => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }
}
