<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Seu pet foi cadastrado no 2pets" — contrato
 * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §7. Sempre transacional
 * (mesma finalidade da coleta: o tutor pediu atendimento); o bloco de oferta comercial só
 * aparece com `marketingOptIn = true`. Envio síncrono, mesmo padrão de
 * `OrganizationInvitationMail`/`ResetPasswordMail` — não há worker de fila garantido em todo
 * ambiente.
 */
class PetRegisteredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $tutorName,
        public readonly string $professionalLabel,
        public readonly string $petName,
        public readonly ?string $continuationUrl,
        public readonly string $unsubscribeUrl,
        public readonly bool $marketingOptIn,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Seu pet foi cadastrado no 2pets',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.pet-registered');
    }

    /** @return array<int, \Illuminate\Mail\Mailables\Attachment> */
    public function attachments(): array
    {
        return [];
    }
}
