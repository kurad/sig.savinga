<?php

namespace App\Http\Requests\Contributions;

use Illuminate\Foundation\Http\FormRequest;

class BulkStoreContributionsRequest extends FormRequest
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
            'period' => ['required', 'date_format:Y-m'],

            // optional defaults for all items
            'paid_date' => ['nullable', 'date_format:Y-m-d'],
            'expected_date' => ['nullable', 'date_format:Y-m-d'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.user_id' => ['required', 'integer', 'exists:users,id'],

            // Either amount > 0 OR missed=true
            'items.*.amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.missed' => ['nullable', 'boolean'],

            // optional per-row overrides
            'items.*.paid_date' => ['nullable', 'date_format:Y-m-d'],
            'items.*.expected_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
    public function messages(): array
    {
        return [
            'period.date_format' => 'period must be in YYYY-MM format.',
            'items.required' => 'items is required.',
            'items.*.user_id.exists' => 'One of the selected members does not exist.',
        ];
    }
}
