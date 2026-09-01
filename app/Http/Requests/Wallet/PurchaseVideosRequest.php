<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseVideosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'video_ids'   => ['required', 'array', 'min:1'],
            'video_ids.*' => ['required', 'integer', 'distinct', 'exists:videos,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'video_ids.required' => 'Please select at least one video to purchase.',
            'video_ids.min'      => 'At least one video must be selected.',
            'video_ids.*.exists' => 'One or more selected videos do not exist.',
        ];
    }
}
