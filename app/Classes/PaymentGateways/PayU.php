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
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Devhammed\LaravelBrickMoney\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PayU implements PaymentGatewayInterface
{
    /**
     * Our own txnid prefix, so an incoming txnid/udf1 (echoed back by PayU on
     * surl/furl) can be mapped straight back to a transactionDbId without
     * needing any side-channel lookup.
     */
    const TXNID_PREFIX = 'TXN';

    protected ?string $key;

    protected ?string $salt;

    protected bool $isRefundSupported;

    protected bool $feesIncludedInAmount;

    protected float $feesRate;

    protected ConnectionType $connectionType;

    /**
     * @param  array<string, mixed>  $pg_data
     */
    public function __construct(array $pg_data = [], ConnectionType|string $connectionType = ConnectionType::TEST)
    {
        $this->key = $pg_data['key'] ?? null;
        $this->salt = $pg_data['salt'] ?? null;
        $this->isRefundSupported = (bool) ($pg_data['supports_refunds'] ?? false);
        $this->feesIncludedInAmount = (bool) ($pg_data['fees_included_in_amount'] ?? false);
        $this->feesRate = (float) ($pg_data['fees_rate'] ?? 0);

        $this->connectionType = $connectionType instanceof ConnectionType
            ? $connectionType
            : ConnectionType::from($connectionType);
    }

    /**
     * PayU's own hosted checkout page. The customer's browser is redirected
     * here (via an auto-submitting form) so no checkout UI needs to be hosted
     * by us.
     */
    protected function checkoutEndpoint(ConnectionType $connectionType): string
    {
        return match ($connectionType) {
            ConnectionType::TEST => 'https://test.payu.in/_payment',
            ConnectionType::PRODUCTION => 'https://secure.payu.in/_payment',
        };
    }

    /**
     * PayU's server-to-server API (verify_payment, cancel_refund_transaction).
     */
    protected function apiEndpoint(ConnectionType $connectionType): string
    {
        return match ($connectionType) {
            ConnectionType::TEST => 'https://test.payu.in/merchant/postservice.php?form=2',
            ConnectionType::PRODUCTION => 'https://info.payu.in/merchant/postservice.php?form=2',
        };
    }

    protected function txnIdFor(Transaction $transaction): string
    {
        return self::TXNID_PREFIX.$transaction->id;
    }

    protected function transactionDbIdFromTxnId(string $txnid): string
    {
        return Str::startsWith($txnid, self::TXNID_PREFIX)
            ? Str::after($txnid, self::TXNID_PREFIX)
            : $txnid;
    }

    protected function calculateFees(Money $amount): Money
    {
        return $amount->multipliedBy($this->feesRate / 100, RoundingMode::HALF_UP);
    }

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
     * Builds the (unhashed) hosted-checkout form fields for a transaction.
     *
     * @return array<string, string>
     */
    protected function buildCheckoutFields(Transaction $transaction, string $key): array
    {
        $amount = $transaction->amount['amount'];

        return [
            'key' => $key,
            'txnid' => $this->txnIdFor($transaction),
            'amount' => (string) $amount->getAmount(),
            'productinfo' => 'Payment for '.$transaction->site_reference_id,
            'firstname' => (string) ($transaction->customer->name ?? ''),
            'email' => (string) ($transaction->customer->email ?? ''),
            'phone' => (string) ($transaction->customer->mobile ?? ''),
            'surl' => route('handlePaymentResponse', ['pgClass' => 'PAYU']),
            'furl' => route('handlePaymentResponse', ['pgClass' => 'PAYU']),
            'udf1' => (string) $transaction->id,
            'udf2' => $transaction->site_reference_id,
        ];
    }

    /**
     * PayU's documented hosted-checkout hash sequence:
     * sha512(key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5||||||salt)
     * udf3-udf5 are unused here (always empty), as are the five reserved
     * trailing slots before the salt.
     *
     * @param  array<string, string>  $fields
     */
    protected function paymentHash(array $fields, string $salt): string
    {
        return hash('sha512', implode('|', [
            $fields['key'],
            $fields['txnid'],
            $fields['amount'],
            $fields['productinfo'],
            $fields['firstname'],
            $fields['email'],
            $fields['udf1'],
            $fields['udf2'],
            '', '', '', // udf3, udf4, udf5
            '', '', '', '', '', // 5 reserved slots
            $salt,
        ]));
    }

    /**
     * PayU's documented reverse-hash sequence for verifying a postback:
     * sha512(salt|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key)
     *
     * @param  array<string, mixed>  $response
     */
    protected function verifyResponseHash(array $response, string $salt, string $key): bool
    {
        $expected = hash('sha512', implode('|', [
            $salt,
            (string) ($response['status'] ?? ''),
            '', '', '', '', '', // 5 reserved slots
            (string) ($response['udf5'] ?? ''),
            (string) ($response['udf4'] ?? ''),
            (string) ($response['udf3'] ?? ''),
            (string) ($response['udf2'] ?? ''),
            (string) ($response['udf1'] ?? ''),
            (string) ($response['email'] ?? ''),
            (string) ($response['firstname'] ?? ''),
            (string) ($response['productinfo'] ?? ''),
            (string) ($response['amount'] ?? ''),
            (string) ($response['txnid'] ?? ''),
            $key,
        ]));

        return hash_equals($expected, (string) ($response['hash'] ?? ''));
    }

    public function handlePaymentRequest(PaymentRequestDTO $paymentRequest, Transaction $transaction): string
    {
        if (! $this->key || ! $this->salt) {
            throw new \Exception('Missing PayU API credentials.');
        }

        // PayU's hosted checkout needs no prior API call - the browser is
        // handed straight to our own auto-submitting form page, which builds
        // and signs the request fields at render time.
        return route('payuCheckout', ['transaction' => $transaction->id]);
    }

    /**
     * Renders the auto-submitting form that hands the customer off to PayU's
     * hosted checkout page. Bound directly as the handler for the
     * `payuCheckout` route (no dedicated controller needed). Resolved fresh
     * by the router with no pg_data, so credentials come from the
     * transaction's own connection rather than $this->key/$this->salt.
     */
    public function checkoutForm(Transaction $transaction): View
    {
        $transaction->loadMissing(['pgConnection', 'customer', 'client']);

        $key = (string) ($transaction->pgConnection->attributes['key'] ?? '');
        $salt = (string) ($transaction->pgConnection->attributes['salt'] ?? '');

        if ($key === '' || $salt === '') {
            throw new \Exception('Missing PayU API credentials.');
        }

        $connectionType = $transaction->pgConnection->type ?? ConnectionType::TEST;

        $fields = $this->buildCheckoutFields($transaction, $key);
        $fields['hash'] = $this->paymentHash($fields, $salt);

        $this->logApiCall($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, $fields, [
            'redirected_to' => $this->checkoutEndpoint($connectionType),
        ]);

        return view('payu.checkout', [
            'checkoutEndpoint' => $this->checkoutEndpoint($connectionType),
            'fields' => $fields,
        ]);
    }

    protected function mapPaymentMode(string $mode): PaymentMethod
    {
        return match ($mode) {
            'CC' => PaymentMethod::CREDIT_CARD,
            'DC' => PaymentMethod::DEBIT_CARD,
            'NB' => PaymentMethod::NETBANKING,
            'UPI' => PaymentMethod::UPI,
            default => PaymentMethod::UNKNOWN,
        };
    }

    protected function mapTransactionStatus(string $status): TransactionStatus
    {
        return match ($status) {
            'success', 'captured' => TransactionStatus::SUCCESS,
            'pending' => TransactionStatus::PENDING,
            'userCancelled', 'dropped' => TransactionStatus::CANCELLED,
            default => TransactionStatus::FAILED,
        };
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function handlePaymentResponse(array $response): PaymentResponseDTO
    {
        $txnid = (string) ($response['txnid'] ?? '');
        $transactionDbId = (string) ($response['udf1'] ?? $this->transactionDbIdFromTxnId($txnid));

        $transaction = Transaction::with(['client', 'pgConnection'])->find($transactionDbId);

        if (! $transaction) {
            throw new \Exception('Transaction not found.');
        }

        $key = (string) ($transaction->pgConnection->attributes['key'] ?? '');
        $salt = (string) ($transaction->pgConnection->attributes['salt'] ?? '');
        $hashVerified = $key !== '' && $salt !== '' && $this->verifyResponseHash($response, $salt, $key);

        $status = TransactionStatus::FAILED;
        $description = (string) ($response['error_Message'] ?? $response['error'] ?? 'Payment failed or was cancelled.');

        if (! $hashVerified) {
            $status = TransactionStatus::FAILED;
            $description = 'Response hash verification failed.';
        } elseif (($response['status'] ?? null) === 'success') {
            $status = TransactionStatus::SUCCESS;
            $description = 'Payment successful.';
        } elseif (($response['status'] ?? null) === 'pending') {
            $status = TransactionStatus::PENDING;
            $description = 'Payment pending.';
        }

        $this->logApiCall(
            $transaction,
            PaymentGatewayRequestType::PAYMENT_INITIATE,
            ['txnid' => $txnid],
            $response,
            $status === TransactionStatus::SUCCESS ? '200' : '400'
        );

        $amount = $transaction->amount['amount'];
        $pgFees = $this->calculateFees($amount);

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: $status,
            transactionId: (string) ($response['mihpayid'] ?? ''),
            description: $description,
            amount: $amount,
            pgFees: $pgFees,
            totalAmount: $amount->plus($pgFees),
            transactionDateTime: isset($response['addedon']) ? CarbonImmutable::parse((string) $response['addedon']) : CarbonImmutable::now(),
            currency: $transaction->currency,
            paymentMethod: $this->mapPaymentMode((string) ($response['mode'] ?? '')),
            clientName: $transaction->client->name,
            pgConnection: $transaction->pgConnection->name,
            pgResponseRaw: $response,
        );
    }

    public function getTransactionStatus(Transaction $transaction): PaymentResponseDTO
    {
        $transaction->loadMissing(['client', 'pgConnection']);

        $key = (string) ($transaction->pgConnection->attributes['key'] ?? $this->key ?? '');
        $salt = (string) ($transaction->pgConnection->attributes['salt'] ?? $this->salt ?? '');

        if ($key === '' || $salt === '') {
            throw new \Exception('Missing PayU API credentials.');
        }

        $connectionType = $transaction->pgConnection->type ?? $this->connectionType;
        $txnid = $this->txnIdFor($transaction);
        $command = 'verify_payment';

        $requestData = ['key' => $key, 'command' => $command, 'var1' => $txnid];
        $hash = hash('sha512', implode('|', [$key, $command, $txnid, $salt]));

        $response = Http::asForm()->post($this->apiEndpoint($connectionType), $requestData + ['hash' => $hash]);

        $this->logApiCall(
            $transaction,
            PaymentGatewayRequestType::STATUS_CHECK,
            $requestData,
            $response->json() ?? ['body' => $response->body()],
            (string) $response->status()
        );

        if ($response->failed()) {
            throw new \Exception('Payment Gateway Error: '.json_encode($response->json() ?? $response->body()));
        }

        $result = $response->json();

        if (! is_array($result) || (int) ($result['status'] ?? 0) !== 1) {
            throw new \Exception('Payment Gateway Error: '.json_encode($result));
        }

        $details = $result['transaction_details'][$txnid] ?? null;

        if (! is_array($details)) {
            throw new \Exception('Payment Gateway Error: transaction details missing from verify_payment response.');
        }

        $status = $this->mapTransactionStatus((string) ($details['status'] ?? ''));
        $amount = isset($details['amt']) ? Money::of($details['amt'], (string) $transaction->currency) : $transaction->amount['amount'];
        $pgFees = $this->calculateFees($amount);

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: $status,
            transactionId: (string) ($details['mihpayid'] ?? $transaction->transaction_id ?? ''),
            description: 'PayU status check: '.($details['status'] ?? 'unknown'),
            amount: $amount,
            pgFees: $pgFees,
            totalAmount: $amount->plus($pgFees),
            transactionDateTime: isset($details['addedon']) ? CarbonImmutable::parse((string) $details['addedon']) : CarbonImmutable::now(),
            currency: $transaction->currency,
            paymentMethod: $this->mapPaymentMode((string) ($details['mode'] ?? '')),
            clientName: $transaction->client->name,
            pgConnection: $transaction->pgConnection->name,
            pgResponseRaw: $details,
        );
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
        if (! $this->isRefundSupported) {
            throw new \Exception('Refunds are not supported by this PayU connection.');
        }

        $transaction->loadMissing(['client', 'pgConnection']);

        if (! $transaction->transaction_id) {
            throw new \Exception('No PayU mihpayid recorded for this transaction.');
        }

        $key = (string) ($transaction->pgConnection->attributes['key'] ?? $this->key ?? '');
        $salt = (string) ($transaction->pgConnection->attributes['salt'] ?? $this->salt ?? '');

        if ($key === '' || $salt === '') {
            throw new \Exception('Missing PayU API credentials.');
        }

        $connectionType = $transaction->pgConnection->type ?? $this->connectionType;
        $command = 'cancel_refund_transaction';
        $mihpayid = $transaction->transaction_id;

        // var2 is a merchant-generated, unique-per-refund token capped at 23
        // characters by PayU.
        $refundToken = substr('RFD'.$transaction->id.'-'.CarbonImmutable::now()->timestamp, 0, 23);

        $requestData = [
            'key' => $key,
            'command' => $command,
            'var1' => $mihpayid,
            'var2' => $refundToken,
            'var3' => (string) $paymentRefundRequest->amount->getAmount(),
        ];
        $hash = hash('sha512', implode('|', [$key, $command, $mihpayid, $salt]));

        $response = Http::asForm()->post($this->apiEndpoint($connectionType), $requestData + ['hash' => $hash]);

        $this->logApiCall(
            $transaction,
            PaymentGatewayRequestType::REFUND,
            $requestData,
            $response->json() ?? ['body' => $response->body()],
            (string) $response->status()
        );

        if ($response->failed()) {
            throw new \Exception('Payment Gateway Error: '.json_encode($response->json() ?? $response->body()));
        }

        $result = $response->json();

        if (! is_array($result) || (int) ($result['status'] ?? 0) !== 1) {
            throw new \Exception('Payment Gateway Error: '.json_encode($result));
        }

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: TransactionStatus::REFUNDED,
            transactionId: (string) $mihpayid,
            description: 'PayU refund '.($result['msg'] ?? 'requested'),
            amount: $paymentRefundRequest->amount,
            pgFees: $transaction->pg_fees['pg_fees'],
            totalAmount: $paymentRefundRequest->amount,
            transactionDateTime: CarbonImmutable::now(),
            currency: $paymentRefundRequest->currency,
            paymentMethod: $transaction->payment_method ?? PaymentMethod::UNKNOWN,
            clientName: $transaction->client->name,
            pgConnection: $transaction->pgConnection->name,
            pgResponseRaw: $result,
        );
    }
}
