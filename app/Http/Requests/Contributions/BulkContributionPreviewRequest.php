<?php

namespace App\Http\Requests\Contributions;

use Illuminate\Foundation\Http\FormRequest;

class BulkContributionPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['treasurer', 'admin'], true);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'owner_type' => $this->input('owner_type', 'user'),
        ]);
    }

    public function rules(): array
    {
        return [
            'period' => ['required', 'date_format:Y-m'],
            'owner_type' => ['nullable', 'in:user,beneficiary'],
            'include_inactive' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'period.required' => 'period is required.',
            'period.date_format' => 'period must be in Y-m format (e.g. 2026-03).',
            'owner_type.in' => 'owner_type must be either user or beneficiary.',
        ];
    }
}