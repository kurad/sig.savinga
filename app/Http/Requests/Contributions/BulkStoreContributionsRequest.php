<?php

namespace App\Http\Requests\Contributions;

use Illuminate\Foundation\Http\FormRequest;

class BulkStoreContributionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['treasurer', 'admin'], true);
    }

    public function rules(): array
    {
        return [
            'period' => ['required', 'date_format:Y-m'],

            // optional default owner for rows that omit owner_type
            'owner_type' => ['nullable', 'in:user,beneficiary'],

            // optional defaults for all items
            'paid_date' => ['nullable', 'date_format:Y-m-d'],
            'expected_date' => ['nullable', 'date_format:Y-m-d'],

            'items' => ['required', 'array', 'min:1'],

            'items.*.owner_type' => ['nullable', 'in:user,beneficiary'],

            'items.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'items.*.beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],

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
            'owner_type.in' => 'owner_type must be either user or beneficiary.',
            'items.required' => 'items is required.',
            'items.*.owner_type.in' => 'Each row owner_type must be either user or beneficiary.',
            'items.*.user_id.exists' => 'One of the selected members does not exist.',
            'items.*.beneficiary_id.exists' => 'One of the selected beneficiaries does not exist.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $defaultOwnerType = $this->input('owner_type', 'user');
            $items = $this->input('items', []);

            foreach ($items as $index => $row) {
                $rowOwnerType = $row['owner_type'] ?? $defaultOwnerType;

                $userId = $row['user_id'] ?? null;
                $beneficiaryId = $row['beneficiary_id'] ?? null;
                $amount = array_key_exists('amount', $row) ? (float) $row['amount'] : null;
                $missed = filter_var($row['missed'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if (!in_array($rowOwnerType, ['user', 'beneficiary'], true)) {
                    $validator->errors()->add(
                        "items.$index.owner_type",
                        'owner_type must be either user or beneficiary.'
                    );
                    continue;
                }

                if ($rowOwnerType === 'user') {
                    if (empty($userId)) {
                        $validator->errors()->add(
                            "items.$index.user_id",
                            'user_id is required when owner_type is user.'
                        );
                    }

                    if (!empty($beneficiaryId)) {
                        $validator->errors()->add(
                            "items.$index.beneficiary_id",
                            'beneficiary_id must not be sent when owner_type is user.'
                        );
                    }
                }

                if ($rowOwnerType === 'beneficiary') {
                    if (empty($beneficiaryId)) {
                        $validator->errors()->add(
                            "items.$index.beneficiary_id",
                            'beneficiary_id is required when owner_type is beneficiary.'
                        );
                    }

                    if (!empty($userId)) {
                        $validator->errors()->add(
                            "items.$index.user_id",
                            'user_id must not be sent when owner_type is beneficiary.'
                        );
                    }
                }

                $hasAmount = !is_null($amount) && $amount > 0;

                if (!$hasAmount && !$missed) {
                    $validator->errors()->add(
                        "items.$index.amount",
                        'Each row must have amount greater than 0 or missed=true.'
                    );
                }

                $rowPaidDate = $row['paid_date'] ?? $this->input('paid_date');
                if ($hasAmount && empty($rowPaidDate)) {
                    $validator->errors()->add(
                        "items.$index.paid_date",
                        'paid_date is required for rows with amount greater than 0.'
                    );
                }
            }
        });
    }
}