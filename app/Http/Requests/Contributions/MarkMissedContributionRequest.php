<?php

namespace App\Http\Requests\Contributions;

use Illuminate\Foundation\Http\FormRequest;

class MarkMissedContributionRequest extends FormRequest
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
            'period'        => ['required', 'date_format:Y-m'],
            'expected_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
    public function messages(): array
    {
        return [
            'user_id.exists'       => 'Member not found.',
            'period.date_format'   => 'period must be in Y-m format (e.g. 2024-03).',
            'expected_date.date_format' => 'expected_date must be in Y-m-d format.',
        ];
    }
}
