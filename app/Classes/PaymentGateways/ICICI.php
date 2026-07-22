<?php

namespace App\Classes\PaymentGateways;

use App\Contracts\PaymentGatewayInterface;
use App\DTO\PaymentRefundDTO;
use App\DTO\PaymentRequestDTO;
use App\DTO\PaymentResponseDTO;
use App\Enums\ConnectionType;
use App\Enums\PaymentGatewayRequestType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Models\PaymentGatewayConnectionApiLog;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Devhammed\LaravelBrickMoney\Currency;
use Devhammed\LaravelBrickMoney\Money;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ICICI implements PaymentGatewayInterface
{
    protected ?string $merchant_id;

    protected ?string $aggregator_id;

    protected ?string $encryption_key;

    protected ?string $sub_merchant_id;

    protected ?string $paymode;

    protected ?string $default_base_url;

    const PAYMENT_ENDPOINT = 'v2/initiateSale';

    const STATUS_CHECK_ENDPOINT = 'command';

    const REFUND_ENDPOINT = 'command';

    const SETTLEMENT_DETAILS_ENDPOINT = 'settlementDetails';

    protected ConnectionType $connectionType;

    protected bool $isRefundSupported = false;

    /**
     * @param  array<string, mixed>  $pg_data
     */
    public function __construct(array $pg_data, ConnectionType|string $connectionType = ConnectionType::TEST)
    {
        $this->merchant_id = $pg_data['merchant_id'] ?? null;
        $this->aggregator_id = $pg_data['aggregator_id'] ?? null;
        $this->encryption_key = $pg_data['encryption_key'] ?? null;
        $this->sub_merchant_id = $pg_data['sub_merchant_id'] ?? null;
        $this->paymode = $pg_data['paymode'] ?? null;
        $this->isRefundSupported = (bool) ($pg_data['supports_refunds'] ?? false);

        $this->connectionType = $connectionType instanceof ConnectionType
            ? $connectionType
            : ConnectionType::from($connectionType);

        $this->default_base_url = match ($this->connectionType) {
            ConnectionType::TEST => 'https://pgpayuat.icicibank.com/tsp/pg/api/',
            ConnectionType::PRODUCTION => 'https://pgpay.icicibank.com/pg/api/',
        };
    }

    protected function getCurrencyCode(string $currency): string
    {
        if ($currency == 'INR') {
            return '356';
        }

        throw new \Exception('Invalid currency code.');
    }

    /**
     * @return array<string, mixed>
     */
    public function constructFromPaymentRequest(PaymentRequestDTO $paymentRequest, Transaction $transaction): array
    {
        $request_data = [
            'merchantId' => $this->merchant_id,
            'aggregatorID' => $this->aggregator_id,
            'merchantTxnNo' => $transaction->id.'-'.$paymentRequest->site_reference_id,
            'amount' => (string) $paymentRequest->amount->getAmount(),
            'currencyCode' => $this->getCurrencyCode((string) $paymentRequest->currency),
            'payType' => '0',
            'customerEmailID' => $paymentRequest->customer['email'],
            'transactionType' => 'SALE',
            'txnDate' => CarbonImmutable::now()->format('YmdHis'),
            'returnURL' => route('handlePaymentResponse', ['pgClass' => 'ICICI']),
            'customerMobileNo' => $paymentRequest->customer['mobile'],
            'addlParam1' => (string) $transaction->id,
            'addlParam2' => $paymentRequest->site_reference_id,
        ];

        ksort($request_data);

        return $request_data;
    }

    /**
     * @param  array<string, mixed>  $requestData
     */
    protected function getConcatenatedRequestString(array $requestData): string
    {
        // assumes that request data is already sorted alphabetically by key
        $concatenatedRequestString = '';
        foreach ($requestData as $value) {
            $concatenatedRequestString .= $value;
        }

        return $concatenatedRequestString;
    }

    /**
     * @param  array<string, mixed>  $requestData
     */
    protected function getHMacDigest(array $requestData): string
    {
        if ($this->encryption_key === null) {
            throw new \Exception('Missing encryption key for HMAC digest.');
        }

        return hash_hmac('sha256', $this->getConcatenatedRequestString($requestData), $this->encryption_key);
    }

    protected function getPaymentEndpoint(): string
    {
        return $this->default_base_url.self::PAYMENT_ENDPOINT;
    }

    protected function getTransactionStatusEndpoint(): string
    {
        return $this->default_base_url.self::STATUS_CHECK_ENDPOINT;
    }

    protected function getSettlementDetailsEndpoint(): string
    {
        return $this->default_base_url.self::SETTLEMENT_DETAILS_ENDPOINT;
    }

    /**
     * @param  array<string, mixed>  $requestData
     */
    protected function logApiCall(Transaction $transaction, PaymentGatewayRequestType $requestType, array $requestData, Response $response): void
    {
        PaymentGatewayConnectionApiLog::create([
            'client_id' => $transaction->client_id,
            'pg_connection_id' => $transaction->pg_connection_id,
            'transaction_id' => $transaction->id,
            'request_type' => $requestType,
            'request_data' => $requestData,
            'response_data' => $response->json() ?? ['body' => $response->body()],
            'response_status' => (string) $response->status(),
        ]);
    }

    public function handlePaymentRequest(PaymentRequestDTO $paymentRequest, Transaction $transaction): string
    {
        $requestObject = $this->constructFromPaymentRequest($paymentRequest, $transaction);
        $requestObject['secureHash'] = $this->getHMacDigest($requestObject);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])
            ->post($this->getPaymentEndpoint(), $requestObject);

        $this->logApiCall($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, $requestObject, $response);

        if ($response->failed()) {
            throw new \Exception('Payment Gateway Error: '.json_encode($response->body()));
        }
        $result = $response->json();

        if (! is_array($result) || $result['responseCode'] !== 'R1000') {
            throw new \Exception('Payment Gateway Error: '.json_encode($result));
        }

        return $result['redirectURI'].'?tranCtx='.$result['tranCtx'];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function mapSucessReponseToPaymentResponseDTO(array $response): PaymentResponseDTO
    {
        // ICICI only supports INR currency, so hardcoding it here
        $amount = Money::of($response['amount'], 'INR');
        $pgFees = Money::of($response['oth_charge'], 'INR');

        $dbTransaction = Transaction::with(['client', 'pgConnection'])->find((string) $response['addlParam1']);

        if (! $dbTransaction) {
            throw new \Exception('Transaction not found.');
        }

        return new PaymentResponseDTO(
            transactionDbId: (string) $response['addlParam1'],
            siteReferenceId: (string) $response['addlParam2'],
            status: $response['responseCode'] === '0000' ? TransactionStatus::SUCCESS : TransactionStatus::FAILED,
            transactionId: (string) $response['txnID'],
            description: (string) $response['respDescription'],
            amount: $amount,
            pgFees: $pgFees,
            totalAmount: $amount->plus($pgFees),
            transactionDateTime: CarbonImmutable::createFromFormat('YmdHis', $response['paymentDateTime']),
            currency: Currency::of('INR'),
            paymentMethod: $this->mapPaymentModes((string) $response['paymentMode']),
            clientName: $dbTransaction->client->name,
            pgConnection: $dbTransaction->pgConnection->name,
            pgResponseRaw: $response,
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function mapResponseToPaymentResponseDTO(array $response): PaymentResponseDTO
    {
        if ($response['responseCode'] === '0000') {
            return $this->mapSucessReponseToPaymentResponseDTO($response);
        }

        [$transactionDbId] = explode('-', (string) $response['merchantTxnNo'], 2);

        $dbTransaction = Transaction::with(['client', 'pgConnection'])->find($transactionDbId);

        if (! $dbTransaction) {
            throw new \Exception('Transaction not found.');
        }

        return $this->mapStatusResponseToPaymentResponseDTO($response, $dbTransaction);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function mapStatusResponseToPaymentResponseDTO(array $response, Transaction $transaction): PaymentResponseDTO
    {
        $status = match ($response['responseCode']) {
            '0000' => TransactionStatus::SUCCESS,
            'P0030' => TransactionStatus::PENDING,
            default => TransactionStatus::FAILED,
        };

        // the STATUS endpoint only returns amount/fees/payment mode once the transaction has completed,
        // so fall back to what was already recorded on the transaction while it's pending.
        $amount = isset($response['amount'])
            ? Money::of($response['amount'], 'INR')
            : $transaction->amount['amount'];

        $pgFees = isset($response['oth_charge']) && is_numeric($response['oth_charge'])
            ? Money::of($response['oth_charge'], 'INR')
            : $transaction->pg_fees['pg_fees'];

        $transactionDateTime = CarbonImmutable::instance(
            isset($response['paymentDateTime'])
                ? CarbonImmutable::createFromFormat('YmdHis', $response['paymentDateTime'])
                : ($transaction->transaction_date_time ?? CarbonImmutable::now())
        );

        $paymentMethod = isset($response['paymentMode'])
            ? $this->mapPaymentModes((string) $response['paymentMode'])
            : ($transaction->payment_method ?? PaymentMethod::UNKNOWN);

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: $status,
            transactionId: (string) ($response['txnID'] ?? $transaction->transaction_id ?? ''),
            description: (string) ($response['respDescription'] ?? ''),
            amount: $amount,
            pgFees: $pgFees,
            totalAmount: $amount->plus($pgFees),
            transactionDateTime: $transactionDateTime,
            currency: Currency::of('INR'),
            paymentMethod: $paymentMethod,
            clientName: $transaction->client->name,
            pgConnection: $transaction->pgConnection->name,
            pgResponseRaw: $response,
        );
    }

    protected function mapPaymentModes(string $paymentMode): PaymentMethod
    {
        return match ($paymentMode) {
            'CARD' => PaymentMethod::CARD,
            'NB' => PaymentMethod::NETBANKING,
            'WALLET' => PaymentMethod::WALLET,
            'UPI' => PaymentMethod::UPI,
            'debit_card' => PaymentMethod::UNKNOWN,
            default => PaymentMethod::UNKNOWN,
        };
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function handlePaymentResponse(array $response): PaymentResponseDTO
    {
        return $this->mapResponseToPaymentResponseDTO($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function constructTransactionStatusRequest(Transaction $transaction): array
    {
        $request_data = [
            'merchantId' => $this->merchant_id,
            'aggregatorID' => $this->aggregator_id,
            // 'merchantTxnNo' => $transaction->id.'-'.$transaction->site_reference_id,
            'originalTxnNo' => $transaction->id.'-'.$transaction->site_reference_id,
            'transactionType' => 'STATUS',
        ];

        ksort($request_data);

        return $request_data;
    }

    public function getTransactionStatus(Transaction $transaction): PaymentResponseDTO
    {
        $requestObject = $this->constructTransactionStatusRequest($transaction);
        $requestObject['secureHash'] = $this->getHMacDigest($requestObject);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])
            ->post($this->getTransactionStatusEndpoint(), $requestObject);

        $this->logApiCall($transaction, PaymentGatewayRequestType::STATUS_CHECK, $requestObject, $response);

        if ($response->failed()) {
            throw new \Exception('Payment Gateway Error: '.json_encode($response->body()));
        }
        $result = $response->json();

        if (! is_array($result)) {
            throw new \Exception('Payment Gateway Error: unexpected response body.');
        }

        return $this->mapStatusResponseToPaymentResponseDTO($result, $transaction);
    }

    public function verifyPayment(Transaction $transaction): PaymentResponseDTO
    {
        return $this->getTransactionStatus($transaction);
    }

    public function isRefundSupported(): bool
    {
        return $this->isRefundSupported;
    }

    public function refundPayment(Transaction $transaction, PaymentRefundDTO $paymentRefundRequest): PaymentResponseDTO
    {
        throw new \Exception('Refunds are not supported by ICICI.');
    }
}
