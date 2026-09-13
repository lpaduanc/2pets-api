<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mesmo padrão de `ResetPasswordMail`/`VerifyEmail`: envio síncrono (sem `ShouldQueue`) — não
 * há worker de fila garantido em todo ambiente, e um convite deve sair na hora do clique do
 * dono, não depender de `queue:work` estar de pé.
 */
class OrganizationInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $organizationName;

    public string $roleLabel;

    public string $acceptUrl;

    public function __construct(Organization $organization, OrganizationInvitation $invitation)
    {
        $this->organizationName = $organization->business_name ?? 'sua organização no 2pets';
        $this->roleLabel = $invitation->role->label();
        $this->acceptUrl = $this->buildAcceptUrl($invitation->token);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Convite para fazer parte de {$this->organizationName} no 2pets",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.organization-invitation');
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    private function buildAcceptUrl(string $token): string
    {
        $appUrl = rtrim(config('app.frontend_url') ?? config('app.url'), '/');

        return "{$appUrl}/organization-invitations/{$token}";
    }
}
