<?php

namespace App\Services\PetAccess;

use App\Enums\VetAccessLevel;
use App\Exceptions\PetAccess\InvalidVetAccessTransitionException;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Decisão do TUTOR sobre o nível de acesso de um veterinário a um pet — aceite de solicitação
 * e alteração posterior do nível concedido.
 *
 * ## Por que "supersede" e não UPDATE no lugar
 *
 * Trocar o nível reescrevendo `access_level` da linha viva apagaria a única evidência de qual
 * nível valia antes e até quando. O CLAUDE.md §5/§6 exige nunca deletar dado e manter trilha
 * de auditoria, então cada nível vigente é UMA LINHA com janela própria: a anterior vai para
 * `superseded` (terminal), ganha `superseded_at` e aponta para a sucessora em
 * `superseded_by_id`. A pergunta "o vet teve `read` de quando até quando?" se responde
 * lendo `granted_at` → `superseded_at` da linha, sem log e sem inferência.
 *
 * `revoked` foi descartado como mecanismo: revogação é ato do tutor de RETIRAR acesso e é o
 * que o app mostra ao tutor e ao vet. Reaproveitá-la para "subiu de read para full" faria o
 * histórico registrar uma revogação que nunca aconteceu.
 *
 * Ordem dentro da transação importa: a linha antiga sai de `accepted` ANTES de a nova entrar,
 * porque `pet_vet_access_accepted_unique` é verificado por statement.
 */
final class VetAccessGrantService
{
    /**
     * Aceite: o nível vem do tutor, nunca de `requested_access_level`.
     *
     * Quando já existe concessão viva (o vet pediu upgrade), ela é substituída pela
     * solicitação aceita — nunca ficam duas linhas `accepted` para o mesmo par.
     */
    public function accept(PetVetAccess $pending, VetAccessLevel $grantedLevel, User $tutor): PetVetAccess
    {
        if ($pending->status !== PetVetAccess::STATUS_PENDING) {
            throw InvalidVetAccessTransitionException::notPending();
        }

        return DB::transaction(function () use ($pending, $grantedLevel, $tutor): PetVetAccess {
            $previous = $this->findGrantedAccess($pending);
            $previous?->supersede($pending);

            $pending->accept($grantedLevel);

            $this->logDecision('PetVetAccess accepted', $pending, $tutor, $previous);

            return $pending;
        });
    }

    /**
     * Alteração de nível de um acesso já concedido, sem nova solicitação — subir ou descer.
     *
     * O tutor decide o nível e não fica preso à escolha da semana passada. Manter o mesmo
     * nível é no-op: gerar linha substituída idêntica só sujaria a trilha.
     */
    public function changeLevel(PetVetAccess $granted, VetAccessLevel $newLevel, User $tutor): PetVetAccess
    {
        if ($granted->status !== PetVetAccess::STATUS_ACCEPTED) {
            throw InvalidVetAccessTransitionException::notAccepted();
        }

        if ($granted->access_level === $newLevel) {
            return $granted;
        }

        return DB::transaction(function () use ($granted, $newLevel, $tutor): PetVetAccess {
            $granted->supersede();

            $successor = $this->openSuccessor($granted, $newLevel, $tutor);
            $granted->linkSuccessor($successor);

            $this->logDecision('PetVetAccess level changed', $successor, $tutor, $granted);

            return $successor;
        });
    }

    /** Concessão viva do mesmo par (pet, vet), se houver. */
    private function findGrantedAccess(PetVetAccess $reference): ?PetVetAccess
    {
        return PetVetAccess::query()
            ->where('pet_id', $reference->pet_id)
            ->where('veterinarian_id', $reference->veterinarian_id)
            ->where('status', PetVetAccess::STATUS_ACCEPTED)
            ->whereKeyNot($reference->getKey())
            ->first();
    }

    /**
     * Nova janela de vigência. `requested_at` fica nulo de propósito: não houve solicitação do
     * vet, a iniciativa foi do tutor.
     */
    private function openSuccessor(PetVetAccess $granted, VetAccessLevel $newLevel, User $tutor): PetVetAccess
    {
        return PetVetAccess::create([
            'pet_id' => $granted->pet_id,
            'veterinarian_id' => $granted->veterinarian_id,
            'granted_by' => $tutor->id,
            'access_level' => $newLevel,
            'requested_access_level' => $granted->requested_access_level,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'granted_at' => now(),
            'responded_at' => now(),
            'is_active' => true,
        ]);
    }

    private function logDecision(string $event, PetVetAccess $access, User $tutor, ?PetVetAccess $previous): void
    {
        Log::info($event, [
            'access_id' => $access->id,
            'pet_id' => $access->pet_id,
            'vet_id' => $access->veterinarian_id,
            'tutor_id' => $tutor->id,
            'granted_access_level' => $access->access_level?->value,
            'superseded_access_id' => $previous?->id,
            'superseded_access_level' => $previous?->access_level?->value,
        ]);
    }
}
