<?php

namespace App\Exceptions\PetAccess;

use App\Enums\VetAccessLevel;
use App\Enums\VetAccessState;
use App\Http\Resources\PetVetAccessResource;
use App\Models\PetVetAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A solicitação não acrescenta nada ao vínculo que o veterinário já tem com o pet.
 *
 * São dois casos, e para o vet eles significam coisas opostas:
 *   - `pending`  → já existe pedido em análise; esperar o tutor.
 *   - `accepted` → o nível já concedido COBRE o solicitado. Pedir `read` tendo `full` é ruído;
 *     pedir `full` tendo `read` é upgrade legítimo e nem chega aqui.
 *
 * O registro existente vai no corpo do 409 para o app conseguir mostrar o estado real.
 */
final class DuplicateVetAccessRequestException extends RuntimeException
{
    public function __construct(
        private readonly PetVetAccess $existingAccess,
        private readonly ?VetAccessLevel $requestedLevel = null,
    ) {
        parent::__construct('Já existe um vínculo ativo para este pet.');
    }

    /** Resultado de negócio esperado, não incidente: não polui o log com stack trace. */
    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        $access = $this->existingAccess->loadMissing(['pet', ...PetVetAccess::PARTICIPANT_RELATIONS]);

        return response()->json([
            'message' => $this->messageFor($access),
            'data' => new PetVetAccessResource($access),
        ], 409);
    }

    private function messageFor(PetVetAccess $access): string
    {
        $petName = $access->pet?->name ?? 'este pet';
        $since = $this->sinceLabel($access);

        if (VetAccessState::fromStatus($access->status) !== VetAccessState::ACCEPTED) {
            return "Já existe uma solicitação para {$petName} aguardando resposta do tutor{$since}.";
        }

        return "Você já tem acesso a {$petName}{$since}{$this->coverageLabel($access)}.";
    }

    /** Explica por que o pedido é redundante em vez de só dizer "já existe". */
    private function coverageLabel(PetVetAccess $access): string
    {
        $granted = $access->access_level;

        if ($granted === null || $this->requestedLevel === null) {
            return '';
        }

        return ", no nível {$granted->label()}, que já cobre o nível {$this->requestedLevel->label()} solicitado";
    }

    private function sinceLabel(PetVetAccess $access): string
    {
        $reference = $access->granted_at ?? $access->requested_at;

        return $reference === null ? '' : ' desde '.$reference->format('d/m/Y');
    }
}
