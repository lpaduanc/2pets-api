<?php

namespace App\Exceptions\Medical;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * O prontuário existe e o profissional pode finalizá-lo, mas o conteúdo mínimo exigido pelo
 * contrato ainda não foi preenchido (contrato §3: diagnóstico OU plano de tratamento, e peso).
 */
final class MedicalRecordFinalizationException extends RuntimeException
{
    public static function missingRequiredFields(): self
    {
        return new self(
            'Para finalizar, preencha o diagnóstico ou o plano de tratamento, e o peso do pet.'
        );
    }

    /** Vacinação só exige peso — ver `MedicalRecordFinalizationService::assertCanFinalize()`. */
    public static function missingWeight(): self
    {
        return new self('Para finalizar, informe o peso do pet.');
    }

    public static function alreadyFinalized(): self
    {
        return new self('Este prontuário já foi finalizado.');
    }

    public static function notFinalizedYet(): self
    {
        return new self('Só é possível adicionar um adendo a um prontuário já finalizado.');
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
