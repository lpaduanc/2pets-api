<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Pedido de cotação ao fornecedor (PLANO 3.5). Na fila (`ShouldQueue`, como `VerifyEmail`): o
 * envio sai para N fornecedores de uma vez e não deve segurar o clique de "Enviar"; o worker
 * `queue` roda no compose. Só strings no payload: nada de modelo serializado com dado da clínica.
 */
class PurchaseQuoteRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param  list<array{name: string, quantity: string, unit: string|null}>  $items */
    public function __construct(
        public string $clinicName,
        public string $supplierName,
        public int $number,
        public string $validUntil,
        public string $responseUrl,
        public array $items,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Pedido de cotação nº {$this->number} — {$this->clinicName}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.purchase-quote-request');
    }
}
