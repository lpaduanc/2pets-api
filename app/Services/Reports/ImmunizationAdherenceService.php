<?php

namespace App\Services\Reports;

use App\DataTransferObjects\Reports\ImmunizationPanelFilters;
use App\Enums\HealthEventLevel;
use App\Models\Pet;
use App\Models\PetDeworming;
use App\Models\User;
use App\Models\Vaccination;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Professional\ProfessionalClientsQuery;
use Illuminate\Support\Collection;

/**
 * Painel de aderência de vacinação/vermífugo — contrato
 * `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`. Escopo por EQUIPE
 * (`CommercialScopeResolver::teamUserIds()`), não por `organization_id`: `vaccinations`/
 * `pet_dewormings` não carregam `organization_id` (achado já registrado no domínio) — mesma
 * regra que corrigiu o bug real de `HospitalizationController`.
 *
 * Decisão de produto, documentada aqui por não haver protocolo vacinal configurável (doc 13):
 * um item SEM `next_dose_date`/`next_date` é lido como "em dia, nada agendado" (`applied`).
 * Com data marcada, o `HealthEventLevel` decide `overdue`/`due_soon`/`upcoming`; os dois
 * últimos entram juntos em `pending` no resumo — só `overdue` tem contador próprio, porque é o
 * único que o filtro `?status=` da tela precisa isolar com destaque.
 */
final class ImmunizationAdherenceService
{
    public function __construct(
        private readonly CommercialScopeResolver $scopeResolver,
        private readonly ProfessionalClientsQuery $clientsQuery,
    ) {}

    /**
     * @return array{summary: array{total: int, applied: int, pending: int, overdue: int, percent_applied: float}, items: Collection<int, array<string, mixed>>}
     */
    public function summarize(User $professional, ImmunizationPanelFilters $filters): array
    {
        $petIds = $this->teamPetIds($professional);
        $items = $this->itemsFor($filters->type, $petIds)
            ->map(fn (object $record) => $this->decorate($record, $filters))
            ->when($filters->status !== null, fn (Collection $items) => $items->filter(fn (array $item) => $item['level'] === $filters->status))
            ->when($filters->from !== null, fn (Collection $items) => $items->filter(fn (array $item) => $item['reference_date'] === null || $item['reference_date']->greaterThanOrEqualTo($filters->from)))
            ->when($filters->to !== null, fn (Collection $items) => $items->filter(fn (array $item) => $item['reference_date'] === null || $item['reference_date']->lessThanOrEqualTo($filters->to)))
            ->values();

        return [
            'summary' => $this->summaryFor($items),
            'items' => $items,
        ];
    }

    /** @return list<int> */
    private function teamPetIds(User $professional): array
    {
        $teamUserIds = $this->scopeResolver->teamUserIds($professional);
        $clientIds = $this->clientsQuery->queryForAny($teamUserIds)->pluck('id');

        return Pet::query()->whereIn('user_id', $clientIds)->pluck('id')->all();
    }

    /** @param  list<int>  $petIds @return Collection<int, object> */
    private function itemsFor(string $type, array $petIds): Collection
    {
        if ($type === 'deworming') {
            return PetDeworming::query()
                ->whereIn('pet_id', $petIds)
                ->latestPerPet()
                ->with('pet.user')
                ->get();
        }

        return Vaccination::query()
            ->whereIn('pet_id', $petIds)
            ->latestPerType()
            ->with('pet.user')
            ->get();
    }

    /** @return array<string, mixed> */
    private function decorate(object $record, ImmunizationPanelFilters $filters): array
    {
        $type = $filters->type;
        $dueDate = $type === 'deworming' ? $record->next_date : $record->next_dose_date;
        $level = $dueDate === null ? 'applied' : HealthEventLevel::fromDaysUntil((int) today()->diffInDays($dueDate, false))->value;

        return [
            'pet_id' => $record->pet_id,
            'pet_name' => $record->pet?->name,
            'label' => $type === 'deworming' ? $record->product_name : $record->vaccine_name,
            'applied_date' => $type === 'deworming' ? $record->applied_date : $record->application_date,
            'due_date' => $dueDate,
            'level' => $level,
            'reference_date' => $dueDate ?? ($type === 'deworming' ? $record->applied_date : $record->application_date),
            'tutor' => $this->tutorBlock($record->pet?->user, $filters->includeContact),
        ];
    }

    /**
     * Bloco de contato do tutor — regra de negócio 4 de
     * `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`: telefone/e-mail só para
     * quem tem `clients.contact.view-bulk`.
     *
     * @return array{id: ?int, name: ?string, phone?: ?string, email?: ?string}
     */
    private function tutorBlock(?User $tutor, bool $includeContact): array
    {
        $block = ['id' => $tutor?->id, 'name' => $tutor?->name];

        return $includeContact ? $block + ['phone' => $tutor?->phone, 'email' => $tutor?->email] : $block;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{total: int, applied: int, pending: int, overdue: int, percent_applied: float}
     */
    private function summaryFor(Collection $items): array
    {
        $total = $items->count();
        $applied = $items->where('level', 'applied')->count();
        $overdue = $items->where('level', 'overdue')->count();
        $pending = $total - $applied - $overdue;

        return [
            'total' => $total,
            'applied' => $applied,
            'pending' => $pending,
            'overdue' => $overdue,
            'percent_applied' => $total === 0 ? 0.0 : round(($applied / $total) * 100, 1),
        ];
    }
}
