<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class ApproveEarningsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'amount'  => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.exists' => 'The selected uploader user does not exist.',
            'amount.gt'      => 'Approved amount must be greater than zero.',
            'amount.regex'   => 'Amount must be a valid currency format (e.g. 100.00).',
        ];
    }
}
