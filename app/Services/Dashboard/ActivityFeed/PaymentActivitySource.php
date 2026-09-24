<?php

namespace App\Services\Dashboard\ActivityFeed;

use App\DataTransferObjects\TutorActivityItem;
use App\Enums\ActivityFeedTone;
use App\Enums\ActivityFeedType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\Support\FrontendRoute;
use Carbon\CarbonInterface;

/**
 * Feed de "minhas compras/pagamentos". `Payment.user_id` é o registro real de cobrança do
 * tutor na plataforma — sinal de agendamento (`purpose = deposit`, ver
 * `AppointmentDepositService`) e acerto de fatura (`purpose = settlement`/`advance`, ver
 * `PaymentService`).
 *
 * `Order`/`OrderItem` (e-commerce) NÃO são usados aqui: não têm controller nem rota
 * registrada em `routes/api.php` — o módulo de loja não está no ar, só o model existe.
 * `Sale`/`Purchase` são do ERP interno da clínica (estoque/venda de balcão), sem `user_id` de
 * tutor — não é "compra do tutor na plataforma".
 *
 * Só estados finais entram no feed (`PAID`/`FAILED`/`REFUNDED`): `PENDING`/`PROCESSING` são
 * transitórios e não representam um evento que já aconteceu.
 */
final class PaymentActivitySource
{
    private const RELEVANT_STATUSES = [
        PaymentStatus::PAID->value,
        PaymentStatus::FAILED->value,
        PaymentStatus::REFUNDED->value,
    ];

    /**
     * @return list<TutorActivityItem>
     */
    public function fetch(User $tutor, int $limit): array
    {
        $payments = Payment::where('user_id', $tutor->id)
            ->whereIn('status', self::RELEVANT_STATUSES)
            ->with(['appointment.pet:id,name', 'invoice.appointment.pet:id,name'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return $payments->map(fn (Payment $payment) => $this->toItem($payment))->all();
    }

    private function toItem(Payment $payment): TutorActivityItem
    {
        $status = PaymentStatus::from($payment->status);
        $meta = $this->statusMeta($status);
        $petName = $this->resolvePetName($payment);

        return new TutorActivityItem(
            id: "payment:{$payment->id}",
            type: ActivityFeedType::PURCHASE,
            icon: 'receipt_long',
            tone: $meta['tone'],
            title: $this->title($status, $payment->purpose),
            description: $this->description($payment, $petName),
            petName: $petName,
            link: FrontendRoute::TUTOR_WALLET,
            createdAt: $this->resolveTimestamp($payment, $status),
        );
    }

    /**
     * @return array{tone: ActivityFeedTone}
     */
    private function statusMeta(PaymentStatus $status): array
    {
        return match ($status) {
            PaymentStatus::PAID => ['tone' => ActivityFeedTone::SUCCESS],
            PaymentStatus::REFUNDED => ['tone' => ActivityFeedTone::WARNING],
            default => ['tone' => ActivityFeedTone::ERROR],
        };
    }

    private function title(PaymentStatus $status, ?string $purpose): string
    {
        if ($status === PaymentStatus::FAILED) {
            return 'Pagamento falhou';
        }

        if ($status === PaymentStatus::REFUNDED) {
            return 'Pagamento reembolsado';
        }

        return $purpose === PaymentPurpose::DEPOSIT->value ? 'Sinal pago' : 'Pagamento confirmado';
    }

    private function description(Payment $payment, ?string $petName): ?string
    {
        $amount = 'R$ '.number_format((float) $payment->amount, 2, ',', '.');

        return $petName === null ? $amount : "{$amount} — {$petName}";
    }

    /**
     * Chain de duas relações (`invoice.appointment.pet`) só para ler um nome de exibição —
     * mantido num único ponto para não espalhar a travessia pelo resto da classe.
     */
    private function resolvePetName(Payment $payment): ?string
    {
        return $payment->appointment?->pet?->name ?? $payment->invoice?->appointment?->pet?->name;
    }

    /**
     * `paid_at` no futuro (dado importado/semeado) viraria "em 1 semana" num feed do que
     * já aconteceu — nesse caso vale o último write do registro.
     */
    private function resolveTimestamp(Payment $payment, PaymentStatus $status): CarbonInterface
    {
        if ($status === PaymentStatus::PAID && $payment->paid_at !== null && ! $payment->paid_at->isFuture()) {
            return $payment->paid_at;
        }

        return $payment->updated_at ?? $payment->created_at;
    }
}
