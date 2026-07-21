<?php

namespace App\Classes\PaymentGateways\Concerns;

use App\Enums\PaymentGatewayRequestType;
use App\Models\PaymentGatewayConnectionApiLog;
use App\Models\Transaction;
use PaypalServerSdkLib\Exceptions\ApiException;

trait LogsApiCalls
{
    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    protected function logApiCall(Transaction $transaction, PaymentGatewayRequestType $requestType, array $requestData, array $responseData, string $responseStatus = '200'): void
    {
        PaymentGatewayConnectionApiLog::create([
            'client_id' => $transaction->client_id,
            'pg_connection_id' => $transaction->pg_connection_id,
            'transaction_id' => $transaction->id,
            'request_type' => $requestType,
            'request_data' => $requestData,
            'response_data' => $responseData,
            'response_status' => $responseStatus,
        ]);
    }

    /**
     * @param  array<string, mixed>  $requestData
     */
    protected function logApiFailure(Transaction $transaction, PaymentGatewayRequestType $requestType, array $requestData, \Throwable $e): void
    {
        $responseBody = $e instanceof ApiException && $e->getHttpResponse()
            ? $e->getHttpResponse()->getRawBody()
            : $e->getMessage();

        $statusCode = $e instanceof ApiException && $e->getHttpResponse()
            ? (string) $e->getHttpResponse()->getStatusCode()
            : '400';

        $this->logApiCall($transaction, $requestType, $requestData, ['error' => $responseBody], $statusCode);
    }
}
