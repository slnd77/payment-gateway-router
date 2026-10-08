<?php

namespace App\Http\Requests\V1;

use App\Enums\SubscriptionPeriod;
use App\Enums\SubscriptionType;
use App\Models\Subscription;
use App\Repositories\ClientConnectionRepository;
use Devhammed\LaravelBrickMoney\Rules\CurrencyRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ValidateSubscriptionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $rawType = $this->decryptedData['subscriptionType'] ?? $this->decryptedData['subscription_type'] ?? null;
        $subscriptionType = $rawType ? strtolower((string) $rawType) : SubscriptionType::PERIODIC->value;

        $rawPeriod = $this->decryptedData['period'] ?? null;
        $defaultExpiresAt = now()->addYears(10)->toIso8601String();

        if (is_string($rawPeriod) && (str_contains(strtolower($rawPeriod), 'year') && preg_match('/\d+/', $rawPeriod))) {
            preg_match('/(\d+)/', $rawPeriod, $matches);
            $years = isset($matches[1]) ? (int) $matches[1] : 10;
            $defaultExpiresAt = now()->addYears($years)->toIso8601String();
            $period = ($subscriptionType === SubscriptionType::PERIODIC->value) ? SubscriptionPeriod::MONTHLY->value : null;
        } else {
            $period = $rawPeriod;
        }

        if (empty($period) && $subscriptionType === SubscriptionType::PERIODIC->value) {
            $period = SubscriptionPeriod::MONTHLY->value;
        }

        $expiresAt = $this->decryptedData['expires_at'] ?? $this->decryptedData['expiresAt'] ?? $defaultExpiresAt;

        $maxCycles = $this->decryptedData['max_cycles'] ?? $this->decryptedData['maxCycles'] ?? null;
        if ($maxCycles === null) {
            $maxCycles = match ($period) {
                SubscriptionPeriod::DAILY->value => 3650,
                SubscriptionPeriod::WEEKLY->value => 520,
                SubscriptionPeriod::MONTHLY->value => 120,
                SubscriptionPeriod::QUARTERLY->value => 40,
                SubscriptionPeriod::YEARLY->value => 10,
                default => ($subscriptionType === SubscriptionType::ON_DEMAND->value ? 240 : 120),
            };
        }

        $this->merge([
            'site_reference_id' => $this->decryptedData['reference_id'] ?? $this->decryptedData['site_reference_id'] ?? null,
            'clientId' => $this->decryptedData['clientId'] ?? $this->decryptedData['client_id'] ?? null,
            'currency' => $this->decryptedData['currency'] ?? null,
            'amount' => $this->decryptedData['amount'] ?? 0,
            'max_amount' => $this->decryptedData['max_amount'] ?? ($this->decryptedData['amount'] ?? 0),
            'auth_amount' => $this->decryptedData['auth_amount'] ?? 0,
            'subscriptionType' => $subscriptionType,
            'period' => $period,
            'interval' => $this->decryptedData['interval'] ?? 1,
            'max_cycles' => $maxCycles,
            'expires_at' => $expiresAt,
            'plan_name' => $this->decryptedData['plan_name'] ?? null,
            'customer' => $this->decryptedData['customer'] ?? [],
            'requestData' => $this->decryptedData ?? [],
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $clientDbId = $this->input('clientDbId');
        $siteRefId = $this->input('site_reference_id');

        return [
            'clientDbId' => ['required', 'integer'],
            'clientId' => ['required', 'string', 'exists:clients,client_id'],
            'currency' => ['required', new CurrencyRule],
            'subscriptionType' => ['required', 'string', new Enum(SubscriptionType::class)],
            'amount' => ['required_if:subscriptionType,'.SubscriptionType::PERIODIC->value, 'numeric', 'min:0'],
            'max_amount' => ['required', 'numeric', 'gte:amount'],
            'auth_amount' => ['nullable', 'numeric', 'min:0'],
            'period' => ['required_if:subscriptionType,'.SubscriptionType::PERIODIC->value, 'nullable', 'string', new Enum(SubscriptionPeriod::class)],
            'interval' => ['nullable', 'integer', 'min:1'],
            'max_cycles' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'plan_name' => ['nullable', 'string', 'max:40'],
            'site_reference_id' => [
                'required',
                'string',
                Rule::unique(Subscription::class, 'site_reference_id')->where(function ($query) use ($clientDbId, $siteRefId) {
                    return $query->where('client_id', $clientDbId)
                        ->where('site_reference_id', $siteRefId);
                }),
            ],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string'],
            'customer.email' => ['required', 'email'],
            'customer.mobile' => ['required', 'numeric'],
            'requestData' => ['required', 'array'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors()->toArray();

        if (! $this->isSelfRedirect()) {
            throw new HttpResponseException(
                response()->json([
                    'status' => 'error',
                    'status_code' => 1,
                    'errors' => $errors,
                ], Response::HTTP_UNPROCESSABLE_ENTITY)
            );
        }

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation error occurred.',
                'errors' => $errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY)
        );
    }

    protected function isSelfRedirect(): bool
    {
        $clientId = $this->decryptedData['clientId'] ?? null;

        if (! $clientId) {
            return true;
        }

        $connection = app(ClientConnectionRepository::class)->getClientPGConnection(
            $clientId,
            1 // is_recurring = 1
        );

        return (bool) ($connection['self_redirect'] ?? true);
    }
}
