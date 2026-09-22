<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Convite de vínculo fora do fluxo de agendamento — contrato
 * `docs/gap-simplesvet/specs/19-portal-do-cliente-spec.md` (Achado 1). Mesma família de
 * `PetRegisteredMail`, sem referência a pet: aqui o profissional só cadastrou a pessoa como
 * cliente, sem paciente/agendamento associado ainda.
 */
class ClientInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $clientName,
        public readonly string $professionalLabel,
        public readonly string $continuationUrl,
        public readonly string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Você foi cadastrado no 2pets',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.client-invite');
    }

    /** @return array<int, \Illuminate\Mail\Mailables\Attachment> */
    public function attachments(): array
    {
        return [];
    }
}
