<?php

namespace App\Services\Booking;

use App\Models\Availability;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CRUD da agenda semanal do profissional (`availabilities`) — fonte de verdade da busca
 * pública de horários (`AvailabilityService::getAvailableSlots()`). `professional_id`/
 * `organization_id` seguem a mesma regra de posse do resto do grupo COMERCIAL
 * (`CommercialScopeResolver::ownershipFor()`).
 */
final class AvailabilityManagementService
{
    public function __construct(private readonly CommercialScopeResolver $scopeResolver) {}

    /**
     * @param  array{location_id: int|null, day_of_week: int, start_time: string, end_time: string, slot_duration: int, buffer_time?: int, is_active?: bool}  $data
     */
    public function create(User $targetProfessional, array $data): Availability
    {
        $availability = Availability::create([
            ...$data,
            ...$this->scopeResolver->ownershipFor($targetProfessional),
        ]);

        // `buffer_time`/`is_active` são opcionais no payload: quando ausentes, o INSERT nem
        // os inclui e quem preenche é o DEFAULT da coluna no banco — `refresh()` traz esse
        // valor de volta para o objeto em memória, senão a resposta mostra `null` para um
        // campo que no banco já nasceu com valor.
        return $availability->refresh();
    }

    /**
     * @param  array{location_id: int|null, day_of_week: int, start_time: string, end_time: string, slot_duration: int, buffer_time?: int, is_active?: bool}  $data
     */
    public function update(Availability $availability, array $data): Availability
    {
        $availability->update($data);

        return $availability;
    }

    public function delete(Availability $availability): void
    {
        $availability->delete();
    }

    /**
     * Apaga toda janela existente para `(professional, location)` e recria a partir de
     * `$windows` — uma única operação, numa transação, para o app nunca ver a agenda
     * momentaneamente vazia entre o DELETE e os INSERTs.
     *
     * @param  list<array{day_of_week: int, start_time: string, end_time: string, slot_duration: int, buffer_time: int, is_active: bool}>  $windows
     * @return Collection<int, Availability>
     */
    public function replaceWeek(User $targetProfessional, ?int $locationId, array $windows): Collection
    {
        $ownership = $this->scopeResolver->ownershipFor($targetProfessional);

        return DB::transaction(function () use ($ownership, $locationId, $windows): Collection {
            Availability::where('professional_id', $ownership['professional_id'])
                ->atLocation($locationId)
                ->delete();

            return collect($windows)->map(
                fn (array $window): Availability => Availability::create([
                    ...$window,
                    ...$ownership,
                    'location_id' => $locationId,
                ])
            );
        });
    }
}
