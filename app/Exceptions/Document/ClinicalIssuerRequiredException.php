<?php

namespace App\Exceptions\Document;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Contrato docs/gap-simplesvet/specs/15-modelos-documento-receituario-assinatura-spec.md,
 * regra de negócio 1: template de conteúdo clínico só é emitido por quem pratica ato clínico.
 */
final class ClinicalIssuerRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Somente um veterinário pode emitir este tipo de documento.');
    }

    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'clinical_issuer_required',
        ], 403);
    }
}
