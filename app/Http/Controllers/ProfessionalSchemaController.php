<?php

namespace App\Http\Controllers;

use App\Services\Registration\ProfessionalSchemaBuilder;
use Illuminate\Http\JsonResponse;

/**
 * `GET /register/professional-schema` — a matriz `ProfessionalType × capacidades`
 * (`docs/segmentacao-cadastro-profissional.md`) servida ao frontend. Fonte única: o backend
 * decide o que cada tipo pode ver/enviar, o app não mantém cópia própria da segmentação.
 */
class ProfessionalSchemaController extends Controller
{
    public function __construct(private readonly ProfessionalSchemaBuilder $schemaBuilder) {}

    public function __invoke(): JsonResponse
    {
        return response()->json($this->schemaBuilder->build());
    }
}
