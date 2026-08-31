<?php

namespace App\Http\Requests\V1;

use App\Enums\PaymentType;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Repositories\ClientConnectionRepository;
use Devhammed\LaravelBrickMoney\Rules\CurrencyRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ValidatePaymentRequest extends FormRequest
{
    protected function prepareForValidation()
    {
        $this->merge([
            'site_reference_id' => $this->decryptedData['reference_id'],
            'clientId' => $this->decryptedData['clientId'],
            'currency' => $this->decryptedData['currency'],
            'amount' => $this->decryptedData['amount'],
            'transactionType' => $this->decryptedData['transactionType'],
            'paymentType' => $this->decryptedData['paymentType'],
            'customer' => $this->decryptedData['customer'],
            'requestData' => $this->decryptedData,
        ]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'clientDbId' => (['required', 'integer']),
            'clientId' => (['required', 'string', 'exists:clients,client_id']),
            'currency' => (['required', new CurrencyRule]),
            'amount' => (['required', 'numeric']),
            'transactionType' => (['required', 'string', new Enum(TransactionType::class)]),
            'paymentType' => (['required', 'string', new Enum(PaymentType::class)]),
            'site_reference_id' => (['required', 'string',
                Rule::unique(Transaction::class)->where(function ($query) {
                    return $query->where('client_id', $this->decryptedData['clientId'])
                        ->where('site_reference_id', $this->decryptedData['reference_id']);
                }),
            ]),

            'customer' => (['required', 'array']),
            'customer.name' => (['required', 'string']),
            'customer.email' => (['required', 'email']),
            'customer.mobile' => (['required', 'numeric']),

            'requestData' => (['required', 'array']),
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        // 1. Extract error messages
        $errors = $validator->errors()->toArray();

        if (! $this->isSelfRedirect()) {
            throw new HttpResponseException(
                response()->json([
                    'status' => 'error',
                    'status_code' => 1,
                    'errors' => $errors,
                ], Response::HTTP_UNPROCESSABLE_ENTITY) // Status Code: 422
            );
        }

        // 3. Stop execution and throw custom JSON response
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation error occurred.',
                'errors' => $errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY) // Status Code: 422
        );
    }

    /**
     * Whether the client's PG connection redirects itself to the bank url,
     * used to decide the shape of the failed-validation response. Defaults
     * to true (self-redirecting) when the connection can't be determined
     * from the still-unvalidated request data.
     */
    protected function isSelfRedirect(): bool
    {
        $clientId = $this->decryptedData['clientId'] ?? null;
        $paymentType = $this->decryptedData['paymentType'] ?? null;

        if (! $clientId || ! $paymentType) {
            return true;
        }

        $connection = app(ClientConnectionRepository::class)->getClientPGConnection(
            $clientId,
            $paymentType === PaymentType::ONE_TIME_PAYMENT->value ? 0 : 1
        );

        return (bool) ($connection['self_redirect'] ?? true);
    }
}
