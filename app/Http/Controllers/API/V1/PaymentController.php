<?php

namespace App\Http\Controllers\API\V1;

use App\DTO\PaymentRequestDTO;
use App\Enums\ClientApiLogResult;
use App\Events\ClientApiEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\ValidatePaymentRequest;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function initiatePayment(ValidatePaymentRequest $request): RedirectResponse|JsonResponse
    {
        try {
            $paymentRequestDto = PaymentRequestDTO::from($request->validated());
            $url = app(TransactionService::class)->initiatePayment($paymentRequestDto);

            return redirect()->away($url);
        } catch (\Throwable $e) {
            $this->logFailure($request, $e);

            return response()->json(['error' => $e->getFile().' '.$e->getLine().' '.$e->getMessage()], 400);
        }

    }

    private function logFailure(ValidatePaymentRequest $request, \Throwable $e): void
    {
        ClientApiEvent::dispatch(
            $request->input('clientDbId'),
            $request->route()?->getName() ?? $request->path(),
            ClientApiLogResult::ERROR,
            $request->input('decryptedData', []),
            ['error' => $e->getMessage()],
            $request->ip(),
        );
    }

    public function handlePaymentResponse(Request $request, string $pgClass): RedirectResponse|JsonResponse
    {
        try {
            $response = $request->all();

            return app(TransactionService::class)->handlePaymentResponse($response, $pgClass);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function getTransactionStatus(Request $request): JsonResponse
    {
        try {
            $transactionDbId = (string) $request->input('transactionDbId');
            $paymentResponse = app(TransactionService::class)->getTransactionStatus($transactionDbId);

            return response()->json($paymentResponse->toArray());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function getTransactionDetails(Request $request, string $referenceId): JsonResponse
    {
        try {
            $paymentResponse = app(TransactionService::class)->getTransactionByReference(
                (string) $request->input('clientDbId'),
                $referenceId
            );

            return response()->json($paymentResponse->toArray());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }

    public function getTransactions(Request $request): JsonResponse
    {
        try {
            $transactions = app(TransactionService::class)->getTransactionsList(
                (string) $request->input('clientDbId'),
                $request->query('start_date') !== null ? (string) $request->query('start_date') : null,
                $request->query('end_date') !== null ? (string) $request->query('end_date') : null,
                (int) $request->query('per_page', 20)
            );

            return response()->json([
                'data' => $transactions->getCollection()->map(fn ($dto) => $dto->toArray())->all(),
                'meta' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
