<?php

namespace App\Http\Requests\V1;

use App\Enums\SubscriptionAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ValidateSubscriptionManageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', new Enum(SubscriptionAction::class)],
            'next_scheduled_time' => ['nullable', 'date'],
        ];
    }
}
