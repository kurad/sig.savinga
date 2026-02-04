<?php

namespace App\Http\Requests\Penalties;

use Illuminate\Foundation\Http\FormRequest;

class ResolvePenaltyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'treasurer'], true);
        
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'], // date of payment/waiver
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
