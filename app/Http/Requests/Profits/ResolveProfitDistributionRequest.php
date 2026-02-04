<?php

namespace App\Http\Requests\Profits;

use Illuminate\Foundation\Http\FormRequest;

class ResolveProfitDistributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'treasurer'], true);
    }

    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
