<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Envelope genérico para mensagem de CRM por e-mail (automação ou campanha) — contrato
 * `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. O corpo já vem RENDERIZADO
 * (`MessageBodyRenderer`) antes de chegar aqui — este Mailable só exibe.
 */
class CrmCampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyText,
        public readonly ?string $unsubscribeUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.crm-message');
    }
}
