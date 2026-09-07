<?php

namespace App\Exceptions\PetAccess;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Nenhum tutor cadastrado com o CPF informado pelo veterinário.
 *
 * O CPF nunca entra na mensagem nem no log: é dado pessoal e a resposta é pública
 * para quem tiver um token de vet.
 */
final class TutorNotFoundException extends RuntimeException
{
    /** Resultado de negocio esperado, nao incidente: nao polui o log com stack trace. */
    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Nenhum tutor encontrado com este CPF. Oriente o tutor a se cadastrar na plataforma antes.',
        ], 404);
    }
}
