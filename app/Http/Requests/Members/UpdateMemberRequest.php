<?php

namespace App\Http\Requests\Members;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMemberRequest extends FormRequest
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
        $userId = $this->route('user')?->id;
        return [
            'name'  => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190', "unique:users,email,{$userId}"],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', "unique:users,phone,{$userId}"],
            'role'  => ['sometimes', 'in:admin,treasurer,member'],
            'password' => ['sometimes', 'nullable', 'string', 'min:6'],
        ];
    }
}
