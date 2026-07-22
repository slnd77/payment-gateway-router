<?php

namespace App\Services;

use App\Classes\Encryption;
use App\Classes\PaymentGateways\PaymentGatewayFactory;
use App\DTO\PaymentRequestDTO;
use App\DTO\PaymentResponseDTO;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Enums\TransactionStatus;
use App\Events\PgTransactionEvent;
use App\Models\ClientCustomer;
use App\Models\Transaction;
use App\Repositories\ClientConnectionRepository;
use App\Repositories\ClientRepository;
use App\Repositories\PgConnectionRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransactionService
{
    public function __construct(
        protected ClientConnectionRepository $clientConnectionRepository,
        protected ClientRepository $clientRepository,
        protected PgConnectionRepository $pgConnectionRepository
    ) {}

    public function initiatePayment(PaymentRequestDTO $paymentRequest): string
    {
        $connection = $this->clientConnectionRepository->getClientPGConnection($paymentRequest->clientId,
            $paymentRequest->paymentType === PaymentType::ONE_TIME_PAYMENT ? 0 : 1);

        if (! $connection) {
            throw new \Exception('PG Connection not found for this client.');
        }

        $paymentGateway = PaymentGatewayFactory::create($connection['pg_connection']);
        $paymentRequest->setPgConnectionId($connection['pg_connection']['id']);
        $transaction = $this->saveTransactionFromPaymentRequest($paymentRequest);

        return $paymentGateway->handlePaymentRequest($paymentRequest, $transaction);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function handlePaymentResponse(array $response, string $pgClass): RedirectResponse
    {
        $paymentGateway = PaymentGatewayFactory::createEmpty($pgClass);
        $paymentResponseDTO = $paymentGateway->handlePaymentResponse($response);

        $transaction = $this->updateTransaction($paymentResponseDTO);
        $client = $this->clientRepository->getClient($transaction->client_id);

        if (! $client) {
            throw new \Exception('Client not found.');
        }

        $encryptedData = $this->getEncryptedData($paymentResponseDTO->toArray(), $client->client_secret);

        return redirect()->away($client->redirect_uri.$client->redirect_uri_separator.'data='.urlencode($encryptedData));
    }

    protected function getEncryptedData(mixed $data, string $key): string
    {
        return Encryption::encrypt($data, $key);
    }

    protected function updateTransaction(PaymentResponseDTO $paymentResponseDTO): Transaction
    {
        $transaction = $this->saveTransactionFromPaymentResponse($paymentResponseDTO);

        // dispatch event only if transaction status changes -to implement
        PgTransactionEvent::dispatch($paymentResponseDTO, $paymentResponseDTO->status);

        return $transaction;
    }

    protected function saveTransactionFromPaymentRequest(PaymentRequestDTO $paymentRequest): Transaction
    {
        return DB::transaction(function () use ($paymentRequest) {
            $client_customer = ClientCustomer::create([
                'client_id' => $paymentRequest->clientDbId,
                'uuid' => Str::ulid(),
                'name' => $paymentRequest->customer['name'],
                'email' => $paymentRequest->customer['email'],
                'mobile' => $paymentRequest->customer['mobile'],
            ]);

            return $client_customer->transactions()->create([
                'client_id' => $paymentRequest->clientDbId,
                'site_reference_id' => $paymentRequest->site_reference_id,
                'pg_connection_id' => $paymentRequest->getPgConnectionId(),
                'amount' => $paymentRequest->amount,
                'currency' => $paymentRequest->currency,
                'transaction_amount' => $paymentRequest->amount,
                'status' => TransactionStatus::PENDING,
                'request_data' => $paymentRequest->requestData,
            ]);
        });

    }

    protected function saveTransactionFromPaymentResponse(PaymentResponseDTO $paymentResponse): Transaction
    {
        return DB::transaction(function () use ($paymentResponse) {
            $transaction = Transaction::find($paymentResponse->transactionDbId);

            if (! $transaction) {
                throw new \Exception('Transaction not found.');
            }

            $transaction->update([
                'transaction_id' => $paymentResponse->transactionId,
                'response_code' => $paymentResponse->status === TransactionStatus::SUCCESS ? '0' : '1',
                'status' => $paymentResponse->status,
                'payment_method' => $paymentResponse->paymentMethod,
                'currency' => $paymentResponse->currency,
                'transaction_amount' => $paymentResponse->amount,
                'pg_fees' => $paymentResponse->pgFees,
                'total_amount' => $paymentResponse->totalAmount,
                'transaction_date_time' => $paymentResponse->transactionDateTime,
                'response_data' => $paymentResponse->pgResponseRaw,
            ]);

            return $transaction;
        });
    }

    public function getTransactionStatus(int|string $transactionDbId): PaymentResponseDTO
    {
        $transaction = Transaction::with(['client', 'pgConnection'])->find($transactionDbId);
        if (! $transaction) {
            throw new \Exception('Transaction not found.');
        }

        $paymentGateway = PaymentGatewayFactory::create((array) $transaction->toArray()['pg_connection']);
        $paymentResponseDTO = $paymentGateway->getTransactionStatus($transaction);

        if ($transaction->status !== $paymentResponseDTO->status) {
            $this->updateTransaction($paymentResponseDTO);
        }

        return $paymentResponseDTO;
    }

    public function getTransactionByReference(int|string $clientDbId, string $referenceId): PaymentResponseDTO
    {
        $transaction = Transaction::with(['client', 'pgConnection'])
            ->where('client_id', $clientDbId)
            ->where('site_reference_id', $referenceId)
            ->first();

        if (! $transaction) {
            throw new \Exception('Transaction not found.');
        }

        return $this->mapTransactionToPaymentResponseDTO($transaction);
    }

    /**
     * @return LengthAwarePaginator<int, PaymentResponseDTO>
     */
    public function getTransactionsList(int|string $clientDbId, ?string $startDate, ?string $endDate, int $perPage = 20): LengthAwarePaginator
    {
        $query = Transaction::with(['client', 'pgConnection'])
            ->where('client_id', $clientDbId);

        if ($startDate) {
            $query->where('transaction_date_time', '>=', CarbonImmutable::createFromFormat('Ymd', $startDate)->startOfDay());
        }

        if ($endDate) {
            $query->where('transaction_date_time', '<=', CarbonImmutable::createFromFormat('Ymd', $endDate)->endOfDay());
        }

        return $query->orderByDesc('transaction_date_time')
            ->paginate(min(max($perPage, 1), 100))
            ->through(fn (Transaction $transaction) => $this->mapTransactionToPaymentResponseDTO($transaction));
    }

    protected function mapTransactionToPaymentResponseDTO(Transaction $transaction): PaymentResponseDTO
    {
        $paymentMethod = PaymentMethod::tryFrom((string) $transaction->getRawOriginal('payment_method')) ?? PaymentMethod::UNKNOWN;

        $transactionDateTime = $transaction->transaction_date_time ?? $transaction->created_at;

        if (! $transactionDateTime instanceof \DateTimeInterface) {
            $transactionDateTime = CarbonImmutable::now();
        }

        $transactionDateTime = CarbonImmutable::instance($transactionDateTime);

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: $transaction->status,
            transactionId: (string) $transaction->transaction_id,
            description: (string) ($transaction->response_data['respDescription'] ?? ''),
            amount: $transaction->transaction_amount['transaction_amount'],
            pgFees: $transaction->pg_fees['pg_fees'],
            totalAmount: $transaction->total_amount['total_amount'],
            transactionDateTime: $transactionDateTime,
            currency: $transaction->currency,
            paymentMethod: $paymentMethod,
            clientName: $transaction->client->name,
            pgConnection: $transaction->pgConnection->name,
            pgResponseRaw: $transaction->response_data ?? [],
        );
    }
}
