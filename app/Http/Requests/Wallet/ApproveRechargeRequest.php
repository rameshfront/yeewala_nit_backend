<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class ApproveRechargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id'          => ['required', 'integer', 'exists:users,id'],
            'requested_amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'approved_amount'  => ['required', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'recharge_id'      => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.exists'      => 'The user does not exist.',
            'requested_amount.gt' => 'Requested amount must be greater than zero.',
            'approved_amount.gte' => 'Approved amount must be zero or positive.',
        ];
    }
}
