<?php

namespace App\Services\Transport;

use App\Enums\PetTransportStatus;
use App\Models\OrganizationMember;
use App\Models\PetTransport;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Leitura do leva e traz (PLANO 3.7): escopo, lista do dia e KPIs.
 *
 * Escopo no padrão da agenda: na organização ativa, os transportes dela; sem organização, os do
 * próprio profissional. Dia/período pelo calendário de São Paulo sobre `scheduled_pickup_at`.
 */
final class PetTransportQueryService
{
    /** @return Builder<PetTransport> */
    public function scoped(User $user): Builder
    {
        $organizationId = $user->activeOrganizationId();

        return PetTransport::query()->when(
            $organizationId !== null,
            fn (Builder $q) => $q->where('organization_id', $organizationId),
            fn (Builder $q) => $q->whereNull('organization_id')->where('professional_id', $user->id),
        );
    }

    /**
     * Possíveis motoristas: membros ATIVOS da organização (sem organização, só o próprio
     * profissional). É a MESMA fonte que valida o `driver_id` da mudança de status — o que a
     * lista mostra sempre passa na validação.
     *
     * @return Collection<int, array{user_id: int, name: string}>
     */
    public function drivers(?int $organizationId, int $professionalId): Collection
    {
        if ($organizationId === null) {
            return User::whereKey($professionalId)->get(['id', 'name'])->map(fn (User $u): array => ['user_id' => $u->id, 'name' => $u->name]);
        }

        return User::query()
            ->whereIn('id', OrganizationMember::where('organization_id', $organizationId)->where('is_active', true)->select('user_id'))
            ->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ->map(fn (User $u): array => ['user_id' => $u->id, 'name' => $u->name])->values();
    }

    public function isDriver(?int $organizationId, int $professionalId, int $userId): bool
    {
        return $this->drivers($organizationId, $professionalId)->contains('user_id', $userId);
    }

    /** @return Collection<int, PetTransport> */
    public function day(User $user, string $date): Collection
    {
        [$from, $to] = $this->range($date, $date);

        return $this->scoped($user)->with(PetTransport::RESOURCE_RELATIONS)
            ->where('scheduled_pickup_at', '>=', $from)->where('scheduled_pickup_at', '<', $to)
            ->orderBy('scheduled_pickup_at')->orderBy('id')->get();
    }

    /**
     * Taxa de sucesso = entregues ÷ (entregues + falhas), 4 casas (0,7500 = 75%); `null` sem
     * nenhum dos dois. Coleta = "a caminho" → "coletado"; entrega = "coletado" → "entregue",
     * em minutos (1 casa), só com os dois horários gravados.
     *
     * @return array{success_rate: string|null, avg_pickup_minutes: string|null, avg_delivery_minutes: string|null, delivered: int, failed: int}
     */
    public function kpis(User $user, string $fromDate, string $toDate): array
    {
        [$from, $to] = $this->range($fromDate, $toDate);
        $rows = $this->scoped($user)->where('scheduled_pickup_at', '>=', $from)->where('scheduled_pickup_at', '<', $to)->get();
        $delivered = $rows->where('status', PetTransportStatus::DELIVERED)->count();
        $failed = $rows->where('status', PetTransportStatus::FAILED)->count();

        return [
            'success_rate' => $delivered + $failed === 0 ? null : number_format($delivered / ($delivered + $failed), 4, '.', ''),
            'avg_pickup_minutes' => $this->average($rows, 'on_the_way_at', 'picked_up_at'),
            'avg_delivery_minutes' => $this->average($rows, 'picked_up_at', 'delivered_at'),
            'delivered' => $delivered,
            'failed' => $failed,
        ];
    }

    /** @param  Collection<int, PetTransport>  $rows */
    private function average(Collection $rows, string $start, string $end): ?string
    {
        $minutes = $rows->filter(fn (PetTransport $t): bool => $t->{$start} !== null && $t->{$end} !== null)
            ->map(fn (PetTransport $t): float => $t->{$start}->diffInSeconds($t->{$end}) / 60);

        return $minutes->isEmpty() ? null : number_format($minutes->avg(), 1, '.', '');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} instantes UTC [início do 1º dia, fim do último) */
    private function range(string $fromDate, string $toDate): array
    {
        $timezone = BusinessClock::timezone();

        return [CarbonImmutable::parse($fromDate, $timezone)->startOfDay()->utc(), CarbonImmutable::parse($toDate, $timezone)->startOfDay()->addDay()->utc()];
    }
}
