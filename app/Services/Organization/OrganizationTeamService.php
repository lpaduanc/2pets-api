<?php

namespace App\Services\Organization;

use App\Enums\OrganizationRole;
use App\Models\Availability;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Service;
use App\Models\User;
use App\Support\ServiceNameNormalizer;
use Illuminate\Support\Collection;

/**
 * "Quem da equipe pode ser escolhido para atender" — Fase 2 do fluxo de agendamento
 * (`docs`: tutor escolhe serviço → escolhe profissional da equipe, ou "qualquer um" →
 * escolhe horário). Único ponto que resolve "estabelecimento (`User`) → equipe
 * (`organization_members` ativos e com papel que atende)".
 */
final class OrganizationTeamService
{
    /**
     * `Organization` que este `User` (a conta que loga como "estabelecimento") possui.
     * Assume no máximo uma organização própria por conta — é o cenário real hoje
     * (`BusinessOrganizationRegistrar` só cria uma). `null` para vet volante ou tutor.
     */
    public function resolveOwnedOrganization(User $establishment): ?Organization
    {
        return $establishment->ownedOrganizations()->first();
    }

    /**
     * Membros ativos, com papel "bookável" (`OrganizationRole::bookableRoles()`), da
     * organização — cada `User` volta com `professional.services` (ativos) já
     * eager-loaded, mais os atributos virtuais `matched_service_price` (quando
     * `$serviceId` casa com um serviço do profissional) e `has_schedule` (agenda
     * cadastrada nesta organização).
     *
     * @return Collection<int, User>
     */
    public function bookableMembers(Organization $organization, ?int $serviceId = null): Collection
    {
        $service = $serviceId !== null ? Service::find($serviceId) : null;

        $members = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->whereIn('role', OrganizationRole::bookableRoles())
            ->with(['user', 'user.professional.services', 'serviceAreas'])
            ->get()
            ->filter(fn (OrganizationMember $member): bool => $member->canServeArea($service?->service_area_id))
            ->map(fn (OrganizationMember $member): ?User => $member->user)
            ->filter()
            ->values();

        $filtered = $this->filterByService($members, $service?->name);

        return $this->annotateSchedule($filtered, $organization);
    }

    /**
     * @param  Collection<int, User>  $members
     * @return Collection<int, User>
     */
    private function filterByService(Collection $members, ?string $serviceName): Collection
    {
        if ($serviceName === null) {
            return $members;
        }

        $normalizedTarget = ServiceNameNormalizer::normalize($serviceName);

        return $members
            ->filter(function (User $member) use ($normalizedTarget): bool {
                $matched = $this->matchedService($member, $normalizedTarget);

                if ($matched === null) {
                    return false;
                }

                $member->setAttribute('matched_service_price', (float) $matched->price);

                return true;
            })
            ->values();
    }

    private function matchedService(User $member, string $normalizedTarget): ?Service
    {
        return $member->professional?->services
            ?->first(fn (Service $service): bool => ServiceNameNormalizer::normalize($service->name) === $normalizedTarget);
    }

    /**
     * @param  Collection<int, User>  $members
     * @return Collection<int, User>
     */
    private function annotateSchedule(Collection $members, Organization $organization): Collection
    {
        $scheduledProfessionalIds = Availability::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->pluck('professional_id')
            ->unique();

        return $members->each(
            fn (User $member) => $member->setAttribute('has_schedule', $scheduledProfessionalIds->contains($member->id))
        );
    }
}
