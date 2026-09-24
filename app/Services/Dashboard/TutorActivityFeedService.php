<?php

namespace App\Services\Dashboard;

use App\DataTransferObjects\TutorActivityItem;
use App\Models\User;
use App\Services\Dashboard\ActivityFeed\AppointmentActivitySource;
use App\Services\Dashboard\ActivityFeed\PaymentActivitySource;
use App\Services\Dashboard\ActivityFeed\TutorNotificationActivitySource;
use App\Services\Dashboard\ActivityFeed\TutorRecordActivitySource;

/**
 * Monta o feed unificado "Atividade Recente" da Início do tutor (`GET /api/dashboard/stats`,
 * `data.recentActivity`) a partir de 4 fontes independentes — cadastro de pet/saúde/conta,
 * agendamento, pagamento e notificação. Cada fonte já devolve no máximo `$limit` itens
 * ordenados por data (top-k por fonte), então o merge final é só ordenar de novo e cortar —
 * nenhuma fonte pode ter mais itens no resultado final do que o próprio `$limit` permitiria.
 */
final class TutorActivityFeedService
{
    public function __construct(
        private readonly TutorRecordActivitySource $recordSource,
        private readonly AppointmentActivitySource $appointmentSource,
        private readonly PaymentActivitySource $paymentSource,
        private readonly TutorNotificationActivitySource $notificationSource,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function recentActivity(User $tutor, int $limit): array
    {
        $items = [
            ...$this->recordSource->fetch($tutor, $limit),
            ...$this->appointmentSource->fetch($tutor, $limit),
            ...$this->paymentSource->fetch($tutor, $limit),
            ...$this->notificationSource->fetch($tutor, $limit),
        ];

        usort($items, fn (TutorActivityItem $a, TutorActivityItem $b) => $b->createdAt <=> $a->createdAt);

        return array_map(
            fn (TutorActivityItem $item) => $item->toArray(),
            array_slice($items, 0, $limit)
        );
    }
}
