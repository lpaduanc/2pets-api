<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use App\Support\PetIdentityMinimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Central authorization helper for pet-scoped endpoints.
 *
 * Problem: many controllers used `$request->user()->pets()->findOrFail(...)` which
 * silently excludes vets with an active `PetVetAccess` grant. That broke the core
 * "tutor autoriza vet a ver dados do pet" flow.
 *
 * Rules:
 *   - Owner (tutor) always has read + write.
 *   - Vet with `active()` PetVetAccess can read.
 *   - Vet with access_level WRITE or FULL can also write (create/update/delete
 *     medical data nested under the pet; pet record itself stays owner-only — see
 *     PetPolicy::update).
 *
 * A ordem de privilégio entre os níveis vive só em `VetAccessLevel` (`covers()`/
 * `valuesAtLeast()`); aqui não se repete lista de valores.
 *   - Anyone else gets 403 (we intentionally prefer 403 over 404 to keep the
 *     frontend UX honest: "you tried to touch this pet, you aren't allowed").
 *
 * This trait NEVER bypasses PetPolicy — it complements it. Controllers that act on
 * the Pet row (rename, delete, change avatar) must still call `$this->authorize('update', $pet)`.
 */
trait AuthorizesPetAccess
{
    /**
     * Resolve a pet for a READ operation (tutor OR any active vet grant).
     *
     * @throws HttpException 403 when the requester has no read access.
     */
    protected function resolvePetForRead(Request $request, int $petId): Pet
    {
        $pet = Pet::findOrFail($petId);
        $user = $request->user();

        if ($this->isPetOwner($user, $pet) || $this->hasActiveVetAccess($user->id, $pet->id)) {
            return $pet;
        }

        abort(403, 'Você não tem acesso aos dados deste pet.');
    }

    /**
     * Resolve a pet for a WRITE operation (tutor OR vet with WRITE/FULL grant).
     *
     * @throws HttpException 403 when the requester is not the owner and has no
     *                       write-capable grant.
     */
    protected function resolvePetForWrite(Request $request, int $petId): Pet
    {
        $pet = Pet::findOrFail($petId);
        $user = $request->user();

        if ($this->isPetOwner($user, $pet)) {
            return $pet;
        }

        if ($this->hasActiveVetAccess($user->id, $pet->id, writeRequired: true)) {
            return $pet;
        }

        abort(403, 'Você não tem permissão de escrita para este pet.');
    }

    /**
     * Check if user is the pet's tutor.
     */
    protected function isPetOwner(?User $user, Pet $pet): bool
    {
        return $user !== null && $user->id === $pet->user_id;
    }

    /**
     * Active vet access lookup. When $writeRequired is true, only WRITE/FULL grants pass.
     */
    protected function hasActiveVetAccess(int $userId, int $petId, bool $writeRequired = false): bool
    {
        $query = PetVetAccess::query()
            ->where('veterinarian_id', $userId)
            ->where('pet_id', $petId)
            ->active();

        if ($writeRequired) {
            $query->whereIn('access_level', VetAccessLevel::valuesAtLeast(VetAccessLevel::WRITE));
        }

        return $query->exists();
    }

    /**
     * Resolve a pet for mutations on the pet row itself (rename, microchip, photo, delete).
     * Only owner or a vet with a FULL grant passes — WRITE is limited to medical records.
     */
    protected function resolvePetForFullMutation(Request $request, int $petId): Pet
    {
        $pet = Pet::findOrFail($petId);
        $user = $request->user();

        if ($this->isPetOwner($user, $pet)) {
            return $pet;
        }

        $hasFull = PetVetAccess::query()
            ->where('veterinarian_id', $user->id)
            ->where('pet_id', $pet->id)
            ->whereIn('access_level', VetAccessLevel::valuesAtLeast(VetAccessLevel::FULL))
            ->active()
            ->exists();

        if ($hasFull) {
            return $pet;
        }

        abort(403, 'Somente o tutor ou um veterinário com acesso completo pode alterar os dados deste pet.');
    }

    /**
     * Matriz de visibilidade de auditoria de pet (item 22 do backlog gap-simplesvet):
     * dono/admin veem tudo; vet com grant ativo só as entradas que ele mesmo causou;
     * qualquer outro é 403. Compartilhado entre `PetAuditController` e o endpoint
     * genérico `ActivityLogController` para não duplicar a mesma regra duas vezes.
     *
     * @return int|null null = sem restrição (dono/admin); int = restringe ao `causer_id`.
     */
    protected function resolvePetAuditVisibility(?User $user, Pet $pet): ?int
    {
        $isOwner = $this->isPetOwner($user, $pet);
        $isAdmin = $user?->hasAnyRole(['admin', 'super_admin']) || $user?->role === 'admin';
        $hasActiveVetGrant = $user ? $this->hasActiveVetAccess($user->id, $pet->id) : false;

        if (! $isOwner && ! $isAdmin && ! $hasActiveVetGrant) {
            abort(403, 'Você não tem permissão para ver a auditoria deste pet.');
        }

        return (! $isOwner && ! $isAdmin) ? $user->id : null;
    }

    /**
     * Invariante de privacidade docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md
     * §B: quem só chegou ao pet por AGENDAMENTO (sem `PetVetAccess`) nunca recebe o cadastro
     * clínico completo embutido numa resposta — só a identidade mínima.
     *
     * Falha para o lado seguro: sem usuário autenticado, devolve minimizado.
     */
    protected function minimizePetUnlessFullAccess(?User $user, ?Pet $pet): ?Pet
    {
        if ($pet === null) {
            return null;
        }

        if ($user !== null && ($this->isPetOwner($user, $pet) || $this->hasActiveVetAccess($user->id, $pet->id))) {
            return $pet;
        }

        return PetIdentityMinimizer::minimize($pet);
    }

    /**
     * Variante em lote da checagem acima, para uma coleção de registros que carregam `pet_id`/
     * `pet` (ex.: `MedicalRecord`) — uma única query de `PetVetAccess`, nunca uma por linha.
     *
     * @param  Collection<int, object>  $records  cada item precisa expor `pet_id` e `pet`
     */
    protected function minimizeUngrantedPetsOn(User $professional, Collection $records): void
    {
        $petIds = $records->pluck('pet_id')->filter()->unique()->values();

        $grantedPetIds = PetVetAccess::query()
            ->where('veterinarian_id', $professional->id)
            ->whereIn('pet_id', $petIds)
            ->active()
            ->pluck('pet_id')
            ->all();

        $records->each(function (object $record) use ($professional, $grantedPetIds): void {
            $pet = $record->pet ?? null;
            if ($pet === null) {
                return;
            }

            $hasFullAccess = in_array($record->pet_id, $grantedPetIds, true) || $this->isPetOwner($professional, $pet);
            if (! $hasFullAccess) {
                $record->setRelation('pet', PetIdentityMinimizer::minimize($pet));
            }
        });
    }
}
