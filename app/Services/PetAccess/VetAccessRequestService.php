<?php

namespace App\Services\PetAccess;

use App\DataTransferObjects\VetAccessRequestData;
use App\Enums\VetAccessLevel;
use App\Exceptions\PetAccess\DuplicateVetAccessRequestException;
use App\Exceptions\PetAccess\TutorNotFoundException;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use App\Notifications\PetVetAccessRequested;
use App\Services\CrmvValidationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Abre o handshake de consentimento "vet solicita → tutor aceita" (CLAUDE.md §3).
 *
 * A solicitação nasce sempre `pending`, com `is_active = false` e `access_level` NULO: o vet
 * só enxerga dado clínico depois que o tutor aceita, e o nível concedido é decisão do tutor.
 * O que o vet manda fica em `requested_access_level`, como indicação de necessidade.
 *
 * Nenhum caminho deste service concede acesso por conta própria.
 */
final class VetAccessRequestService
{
    public function __construct(
        private readonly CrmvValidationService $crmvValidationService,
    ) {}

    public function request(User $vet, VetAccessRequestData $data): PetVetAccess
    {
        try {
            return DB::transaction(fn (): PetVetAccess => $this->openPendingRequest($vet, $data));
        } catch (UniqueConstraintViolationException $violation) {
            throw $this->translateRace($vet, $data, $violation);
        }
    }

    private function openPendingRequest(User $vet, VetAccessRequestData $data): PetVetAccess
    {
        $pet = $this->resolvePetWithOwner($data);
        $tutor = $pet->user;

        $this->guardAgainstRedundantRequest($pet->id, $vet->id, $data->requestedAccessLevel);

        $access = $this->createPendingAccess($pet, $vet, $tutor, $data);

        $this->notifyTutor($access, $pet, $tutor, $vet);

        Log::info('PetVetAccess requested', [
            'access_id' => $access->id,
            'vet_id' => $vet->id,
            'pet_id' => $pet->id,
            'tutor_id' => $tutor->id,
            'requested_access_level' => $data->requestedAccessLevel->value,
        ]);

        return $access;
    }

    /**
     * `access_level` nasce NULO de propósito: nível concedido é decisão do tutor no aceite, e
     * o que o vet indicou fica separado em `requested_access_level`.
     */
    private function createPendingAccess(Pet $pet, User $vet, User $tutor, VetAccessRequestData $data): PetVetAccess
    {
        return PetVetAccess::create([
            'pet_id' => $pet->id,
            'veterinarian_id' => $vet->id,
            'granted_by' => $tutor->id,
            'access_level' => null,
            'requested_access_level' => $data->requestedAccessLevel,
            'message' => $data->message,
            'status' => PetVetAccess::STATUS_PENDING,
            'requested_at' => now(),
            'is_active' => false,
        ]);
    }

    /** Devolve o pet sempre com a relação `user` (tutor) carregada. */
    private function resolvePetWithOwner(VetAccessRequestData $data): Pet
    {
        if ($data->isForExistingPet()) {
            return Pet::query()->with('user')->findOrFail($data->petId);
        }

        $tutor = $this->findTutorByCpf($data);
        $pet = Pet::create(array_merge($data->petData, ['user_id' => $tutor->id]));
        $pet->setRelation('user', $tutor);

        return $pet;
    }

    /**
     * Lookup por igualdade em `users.cpf` (dígitos, índice único `users_cpf_unique`). O CPF
     * chega normalizado pelo value object — nunca aplicar função sobre a coluna aqui, sob
     * pena de trocar um index scan por seq scan na tabela inteira de usuários.
     */
    private function findTutorByCpf(VetAccessRequestData $data): User
    {
        $tutor = $data->tutorCpf === null
            ? null
            : User::query()->where('cpf', $data->tutorCpf->digits)->first();

        if ($tutor === null) {
            throw new TutorNotFoundException;
        }

        return $tutor;
    }

    /**
     * Pedido pendente bloqueia qualquer outro. Concessão viva só bloqueia quando o nível dela
     * JÁ COBRE o solicitado — pedir mais do que se tem é upgrade legítimo e é justamente o
     * caso que a regra nova precisa deixar passar.
     */
    private function guardAgainstRedundantRequest(int $petId, int $vetId, VetAccessLevel $requested): void
    {
        $pending = $this->findAccessWithStatus($petId, $vetId, PetVetAccess::STATUS_PENDING);
        if ($pending !== null) {
            throw new DuplicateVetAccessRequestException($pending, $requested);
        }

        $granted = $this->findAccessWithStatus($petId, $vetId, PetVetAccess::STATUS_ACCEPTED);
        if ($granted?->access_level?->covers($requested) === true) {
            throw new DuplicateVetAccessRequestException($granted, $requested);
        }
    }

    private function findAccessWithStatus(int $petId, int $vetId, string $status): ?PetVetAccess
    {
        return PetVetAccess::query()
            ->where('pet_id', $petId)
            ->where('veterinarian_id', $vetId)
            ->where('status', $status)
            ->first();
    }

    /**
     * `guardAgainstRedundantRequest()` é só o caminho rápido; a garantia real contra duplicata
     * é o índice parcial `pet_vet_access_pending_unique`. Quando duas requisições correm juntas,
     * o INSERT perdedor estoura 23505 — traduzimos para o mesmo 409 do caminho feliz em vez de
     * devolver 500.
     *
     * A reconsulta acontece FORA da transação (já desfeita pelo throw): dentro dela o Postgres
     * recusaria qualquer query com "current transaction is aborted".
     */
    private function translateRace(User $vet, VetAccessRequestData $data, UniqueConstraintViolationException $violation): Throwable
    {
        if (! $data->isForExistingPet()) {
            return $violation;
        }

        $existing = $this->findAccessWithStatus((int) $data->petId, $vet->id, PetVetAccess::STATUS_PENDING);

        return $existing === null
            ? $violation
            : new DuplicateVetAccessRequestException($existing, $data->requestedAccessLevel);
    }

    /**
     * Falha de notificação não pode derrubar a solicitação: o consentimento já está gravado e
     * o tutor continua vendo o pedido na lista de pendentes do app.
     */
    private function notifyTutor(PetVetAccess $access, Pet $pet, User $tutor, User $vet): void
    {
        try {
            $tutor->notify(new PetVetAccessRequested($access, $pet, $vet, $this->crmvLabel($vet)));
        } catch (Throwable $failure) {
            Log::error('Failed to dispatch PetVetAccessRequested notification', [
                'access_id' => $access->id,
                'pet_id' => $pet->id,
                'tutor_id' => $tutor->id,
                'error' => $failure->getMessage(),
            ]);
        }
    }

    private function crmvLabel(User $vet): ?string
    {
        $professional = $vet->professional;

        if ($professional?->crmv === null) {
            return null;
        }

        return $this->crmvValidationService->displayLabel($professional->crmv, $professional->crmv_state);
    }
}
