<?php

namespace App\Notifications;

use App\Models\Sale;
use App\Notifications\Support\FrontendRoute;
use App\Services\Commercial\QuoteIssuer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * E-mail do orçamento enviado ao tutor — docs/gap-simplesvet/24-orcamentos.md ("send gera
 * PDF, notifica o tutor").
 *
 * Leva o link de aprovação SEM LOGIN, porque o tutor que decide em casa com a família pode não
 * ter o app, e o PDF anexo, que é o documento que a clínica emitiu. Só é despachado por
 * `NotificationService` quando o canal EMAIL de `quote_received` está ligado para a pessoa.
 *
 * Nada clínico aqui: clínica, animal, número, total e validade — o mesmo recorte da página
 * pública. O token vai em texto puro no corpo (é o próprio link) e, por ser `ShouldQueue`,
 * também no payload da fila até o worker entregar; o banco continua só com o hash.
 */
class QuoteReceivedMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Sale $quote,
        private readonly string $plainToken,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $quote = $this->quote->loadMissing('pet');
        $issuer = QuoteIssuer::for($quote);
        $total = 'R$ '.number_format((float) $quote->total, 2, ',', '.');
        $petName = $quote->pet?->name;

        $mail = (new MailMessage)
            ->subject("Orçamento nº {$quote->number}".($petName ? " para {$petName}" : '')." — {$issuer['name']}")
            ->greeting("Olá, {$notifiable->name}!")
            ->line("**{$issuer['name']}** enviou um orçamento".($petName ? " para **{$petName}**" : '').'.')
            ->line("Total: **{$total}** · válido até **{$quote->valid_until?->format('d/m/Y')}**.")
            ->action('Ver e decidir', FrontendRoute::absolute(FrontendRoute::publicQuoteDecision($this->plainToken)))
            ->line('Você pode aprovar ou recusar pelo link acima, sem precisar entrar no app, ou pelo próprio app do 2pets. O link vale para uma única decisão.')
            ->line('O orçamento completo está em anexo.');

        if ($quote->pdf_path !== null && Storage::disk('local')->exists($quote->pdf_path)) {
            $mail->attachData(
                Storage::disk('local')->get($quote->pdf_path),
                sprintf('orcamento-%d-v%d.pdf', $quote->number ?? $quote->id, $quote->version),
                ['mime' => 'application/pdf'],
            );
        }

        return $mail;
    }
}
