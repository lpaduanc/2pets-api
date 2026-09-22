<?php

namespace App\Services\Crm;

use App\DataTransferObjects\Crm\ClientInteractionSummary;
use App\Enums\AppointmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SaleStatus;
use App\Models\Appointment;
use App\Models\ClientRelationshipProfile;
use App\Models\Invoice;
use App\Models\Sale;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Professional\ProfessionalClientsQuery;
use Illuminate\Support\Collection;

/**
 * Recalcula `client_relationship_profiles` para TODOS os clientes de um profissional/equipe —
 * contrato `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`, regra de negócio 4
 * (lazy: chamado quando a tela abre e o cache está vencido, mais o comando
 * `crm:recalculate-client-profiles` para rodar agendado).
 *
 * Três queries agregadas (uma por fonte), nunca uma por cliente — mesmo cuidado de
 * performance já praticado em `ProfessionalDashboardStatsService`.
 */
final class ClientRelationshipProfileRecalculator
{
    public function __construct(
        private readonly CommercialScopeResolver $scopeResolver,
        private readonly ProfessionalClientsQuery $clientsQuery,
        private readonly ClientLifecycleService $lifecycleService,
        private readonly AbcClassificationService $abcClassificationService,
        private readonly ClientOriginResolver $originResolver,
    ) {}

    /**
     * Cache lazy — regra de negócio 4 da spec: recalcula só quando não existe nenhum perfil
     * ainda ou o mais recente já passou de `ClientRelationshipProfile::STALE_AFTER_HOURS`.
     */
    public function ensureFreshFor(User $professional): void
    {
        if ($this->isFreshFor($professional)) {
            return;
        }

        $this->recalculateForProfessional($professional);
    }

    private function isFreshFor(User $professional): bool
    {
        $ownership = $this->scopeResolver->ownershipFor($professional);

        $latest = ClientRelationshipProfile::query()
            ->forCommercialScope($ownership['organization_id'], $ownership['professional_id'])
            ->max('recalculated_at');

        if ($latest === null) {
            return false;
        }

        return \Carbon\Carbon::parse($latest)->gt(now()->subHours(ClientRelationshipProfile::STALE_AFTER_HOURS));
    }

    public function recalculateForProfessional(User $professional): int
    {
        $teamUserIds = $this->scopeResolver->teamUserIds($professional);
        $clientIds = $this->clientsQuery->queryForAny($teamUserIds)->pluck('id');

        if ($clientIds->isEmpty()) {
            return 0;
        }

        $ownership = $this->scopeResolver->ownershipFor($professional);
        $summaries = $this->summariesByClient($professional, $clientIds);

        $clientIds->each(fn (int $clientId) => $this->upsertProfile(
            $teamUserIds,
            $clientId,
            $ownership,
            $summaries->get($clientId) ?? ClientInteractionSummary::empty(),
        ));

        $this->abcClassificationService->recalculate($ownership['organization_id'], $ownership['professional_id']);

        return $clientIds->count();
    }

    /**
     * @param  Collection<int, int>  $clientIds
     * @return Collection<int, ClientInteractionSummary>
     */
    private function summariesByClient(User $professional, Collection $clientIds): Collection
    {
        $appointments = $this->appointmentWindows($professional, $clientIds);
        $invoices = $this->invoiceWindows($professional, $clientIds);
        $sales = $this->saleWindows($professional, $clientIds);

        return $clientIds->mapWithKeys(fn (int $clientId): array => [
            $clientId => ClientInteractionSummary::merge(
                $appointments->get($clientId),
                $invoices->get($clientId),
                $sales->get($clientId),
            ),
        ]);
    }

    /**
     * `professional_id IN teamUserIds`, não `organization_id`: `appointments` não carrega
     * `organization_id` de forma confiável para todo fluxo (achado já registrado em
     * `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`) — a mesma regra que já
     * corrigiu o bug de `HospitalizationController`.
     *
     * @param  Collection<int, int>  $clientIds
     */
    private function appointmentWindows(User $professional, Collection $clientIds): Collection
    {
        $teamUserIds = $this->scopeResolver->teamUserIds($professional);

        return Appointment::query()
            ->whereIn('professional_id', $teamUserIds)
            ->whereIn('client_id', $clientIds)
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->selectRaw('client_id, MIN(appointment_date) as first_at, MAX(appointment_date) as last_at')
            ->groupBy('client_id')
            ->get()
            ->keyBy('client_id');
    }

    /** @param  Collection<int, int>  $clientIds */
    private function invoiceWindows(User $professional, Collection $clientIds): Collection
    {
        [$since365, $since90, $since30] = $this->windowStarts();

        return $this->scopeResolver->scopeQuery(Invoice::query(), $professional)
            ->whereIn('client_id', $clientIds)
            ->where('status', InvoiceStatus::PAID->value)
            ->selectRaw(
                'client_id, MIN(payment_date) as first_at, MAX(payment_date) as last_at,'.
                'SUM(total) FILTER (WHERE payment_date >= ?) as spent_365,'.
                'SUM(total) FILTER (WHERE payment_date >= ?) as spent_90,'.
                'SUM(total) FILTER (WHERE payment_date >= ?) as spent_30',
                [$since365, $since90, $since30]
            )
            ->groupBy('client_id')
            ->get()
            ->keyBy('client_id');
    }

    /** @param  Collection<int, int>  $clientIds */
    private function saleWindows(User $professional, Collection $clientIds): Collection
    {
        [$since365, $since90, $since30] = $this->windowStarts();

        return $this->scopeResolver->scopeQuery(Sale::query(), $professional)
            ->whereIn('client_id', $clientIds)
            ->salesOnly()
            ->where('status', SaleStatus::PAID->value)
            ->selectRaw(
                'client_id, MIN(sold_at) as first_at, MAX(sold_at) as last_at,'.
                'SUM(total) FILTER (WHERE sold_at >= ?) as spent_365,'.
                'SUM(total) FILTER (WHERE sold_at >= ?) as spent_90,'.
                'SUM(total) FILTER (WHERE sold_at >= ?) as spent_30',
                [$since365, $since90, $since30]
            )
            ->groupBy('client_id')
            ->get()
            ->keyBy('client_id');
    }

    /** @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon, 2: \Carbon\Carbon} */
    private function windowStarts(): array
    {
        $now = now();

        return [$now->copy()->subDays(365), $now->copy()->subDays(90), $now->copy()->subDays(30)];
    }

    /**
     * @param  list<int>  $teamUserIds
     * @param  array{organization_id: ?int, professional_id: int}  $ownership
     */
    private function upsertProfile(array $teamUserIds, int $clientId, array $ownership, ClientInteractionSummary $summary): void
    {
        $profile = $this->findExistingProfile($ownership, $clientId);
        $isNewProfile = $profile === null;
        $profile ??= new ClientRelationshipProfile([
            'organization_id' => $ownership['organization_id'],
            'professional_id' => $ownership['professional_id'],
            'client_id' => $clientId,
        ]);

        $profile->fill([
            'first_interaction_at' => $summary->firstInteractionAt,
            'last_interaction_at' => $summary->lastInteractionAt,
            'total_spent_365d' => $summary->spent365,
            'total_spent_90d' => $summary->spent90,
            'total_spent_30d' => $summary->spent30,
            'lifecycle_stage' => $this->lifecycleService->classify($summary->lastInteractionAt)->value,
            'recalculated_at' => now(),
        ]);

        if ($isNewProfile) {
            $profile->client_origin_id = $this->originResolver->resolveAutomaticOriginId($teamUserIds, $clientId);
        }

        $profile->save();
    }

    /** @param  array{organization_id: ?int, professional_id: int}  $ownership */
    private function findExistingProfile(array $ownership, int $clientId): ?ClientRelationshipProfile
    {
        return ClientRelationshipProfile::query()
            ->forCommercialScope($ownership['organization_id'], $ownership['professional_id'])
            ->where('client_id', $clientId)
            ->first();
    }
}
