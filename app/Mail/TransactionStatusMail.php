<?php

namespace App\Mail;

use App\DTO\PaymentResponseDTO;
use App\Enums\TransactionStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TransactionStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly PaymentResponseDTO $paymentResponseDTO,
        public readonly TransactionStatus $transactionStatus,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Transaction '.$this->transactionStatus->value.' - '.$this->paymentResponseDTO->siteReferenceId,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.transaction-status',
        );
    }
}
