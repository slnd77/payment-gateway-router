<?php

namespace App\Contracts;

use App\DTO\PaymentRefundDTO;
use App\DTO\PaymentRequestDTO;
use App\DTO\PaymentResponseDTO;
use App\Models\Transaction;

interface PaymentGatewayInterface
{
    public function handlePaymentRequest(PaymentRequestDTO $paymentRequest, Transaction $transaction): string;

    public function verifyPayment(Transaction $transaction): PaymentResponseDTO;

    public function getTransactionStatus(Transaction $transaction): PaymentResponseDTO;

    /**
     * @param  array<string, mixed>  $response
     */
    public function handlePaymentResponse(array $response): PaymentResponseDTO;

    public function isRefundSupported(): bool;

    public function refundPayment(Transaction $transaction, PaymentRefundDTO $paymentRefundRequest): PaymentResponseDTO;
}
