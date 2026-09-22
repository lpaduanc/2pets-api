<?php

namespace App\Services\Booking;

use App\Enums\OrganizationRole;
use App\Models\Appointment;
use App\Models\OrganizationMember;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `GET professional/agenda/day` — item 21 do backlog gap-simplesvet. Grade recurso
 * (profissional) × hora para o dia inteiro, numa quantidade fixa de queries — nunca uma
 * consulta por profissional nem por agendamento (ver `DayAgendaByResourceQueryCountTest`).
 *
 * "Recurso" aqui é o profissional (`User`) que já recebe agendamento hoje
 * (`appointments.professional_id`), o mesmo dono de conta usado por
 * `professional/appointments`. Com organização ativa, a grade mostra toda a equipe bookável
 * (`OrganizationRole::bookableRoles()`); sem organização (vet volante), a grade tem um único
 * recurso — a própria conta autenticada.
 */
final class DayAgendaService
{
    /**
     * @return Collection<int, array{id: int, name: string, role: ?string, role_label: ?string, appointments: Collection<int, Appointment>}>
     */
    public function build(User $requestingUser, Carbon $date, ?int $locationId, ?int $areaId): Collection
    {
        $organizationId = $requestingUser->activeOrganizationId();

        $resources = $organizationId !== null
            ? $this->organizationResources($organizationId, $locationId, $areaId)
            : $this->soloResource($requestingUser);

        $appointmentsByProfessional = $this->appointmentsForDate($resources->pluck('id'), $date, $locationId);

        return $resources->map(fn (array $resource): array => [
            ...$resource,
            'appointments' => $appointmentsByProfessional->get($resource['id'], collect()),
        ])->values();
    }

    /**
     * @return Collection<int, array{id: int, name: string, role: string, role_label: string}>
     */
    private function organizationResources(int $organizationId, ?int $locationId, ?int $areaId): Collection
    {
        $members = $this->bookableMembers($organizationId, $areaId);

        if ($locationId !== null) {
            $members = $this->restrictToLocation($members, $locationId);
        }

        return $members->map($this->toResourceRow(...))->values();
    }

    /**
     * @return Collection<int, OrganizationMember>
     */
    private function bookableMembers(int $organizationId, ?int $areaId): Collection
    {
        return OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereIn('role', OrganizationRole::bookableRoles())
            ->with(['user:id,name', 'serviceAreas:id'])
            ->get()
            ->filter(fn (OrganizationMember $member): bool => $member->user !== null && $member->canServeArea($areaId));
    }

    /**
     * @param  Collection<int, OrganizationMember>  $members
     * @return Collection<int, OrganizationMember>
     */
    private function restrictToLocation(Collection $members, int $locationId): Collection
    {
        $staffUserIds = $this->staffUserIdsForLocation($locationId);

        return $members->filter(fn (OrganizationMember $member): bool => $staffUserIds->contains($member->user_id));
    }

    /**
     * @return array{id: int, name: string, role: string, role_label: string}
     */
    private function toResourceRow(OrganizationMember $member): array
    {
        return [
            'id' => $member->user_id,
            'name' => $member->user->name,
            'role' => $member->role->value,
            'role_label' => $member->role->label(),
        ];
    }

    /**
     * @return Collection<int, int>
     */
    private function staffUserIdsForLocation(int $locationId): Collection
    {
        return DB::table('location_staff')
            ->where('location_id', $locationId)
            ->where('is_active', true)
            ->pluck('staff_id');
    }

    /**
     * @return Collection<int, array{id: int, name: string, role: null, role_label: null}>
     */
    private function soloResource(User $user): Collection
    {
        return collect([[
            'id' => $user->id,
            'name' => $user->name,
            'role' => null,
            'role_label' => null,
        ]]);
    }

    /**
     * @param  Collection<int, int>  $professionalIds
     * @return Collection<int, Collection<int, Appointment>> chave = professional_id
     */
    private function appointmentsForDate(Collection $professionalIds, Carbon $date, ?int $locationId): Collection
    {
        $startOfDay = $date->copy()->startOfDay();
        $startOfNextDay = $startOfDay->copy()->addDay();

        return Appointment::query()
            ->whereIn('professional_id', $professionalIds)
            ->where('appointment_date', '>=', $startOfDay)
            ->where('appointment_date', '<', $startOfNextDay)
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            // `appointmentType` alimenta a cor da grade (item 14, achado do frontend) — sem
            // eager load aqui, `AppointmentResource` devolveria `appointment_type: null`
            // mesmo quando o agendamento tem um vínculo, o que é pior que omitir o campo.
            ->with(['pet', 'client', 'service', 'appointmentType'])
            ->orderBy('appointment_time')
            ->get()
            ->groupBy('professional_id');
    }
}
