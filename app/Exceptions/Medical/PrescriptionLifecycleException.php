<?php

namespace App\Exceptions\Medical;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Violação da máquina de estados da prescrição (contrato
 * docs/atendimento-veterinario/03-contrato-receituario.md §1): emitida é imutável, cancelada
 * é terminal, e só uma prescrição standalone pode ser emitida manualmente.
 */
final class PrescriptionLifecycleException extends RuntimeException
{
    public static function alreadyIssued(): self
    {
        return new self('Esta prescrição já foi emitida e não pode mais ser editada. Para corrigir, cancele e emita uma nova.');
    }

    public static function alreadyCanceled(): self
    {
        return new self('Esta prescrição já foi cancelada.');
    }

    public static function notStandalone(): self
    {
        return new self('Só uma prescrição sem atendimento vinculado pode ser emitida manualmente — a vinculada é emitida junto com a finalização do prontuário.');
    }

    public static function notIssuedYet(): self
    {
        return new self('Só é possível cancelar uma prescrição já emitida.');
    }

    /** Resultado de negócio esperado, não incidente: não polui o log com stack trace. */
    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
