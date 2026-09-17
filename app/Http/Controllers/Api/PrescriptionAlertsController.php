<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReferenceData\ReferenceDataCacheService;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/reference/prescription-alerts` — contrato
 * docs/atendimento-veterinario/03-contrato-receituario.md §5. Lista curada de alertas
 * clínicos (Apêndice A de docs/atendimento-veterinario/02-receituario-dominio.md), servida
 * como dado de referência: baixo volume, muda raramente, nunca específico de um tutor/pet.
 *
 * O MATCH em si (espécie × substância, doença crônica × substância, alergia × medicamento)
 * é calculado no FRONTEND, que busca esta lista uma vez e cacheia na store (contrato §5) —
 * este endpoint só serve o dado, nunca calcula alerta para uma prescrição específica.
 */
final class PrescriptionAlertsController extends Controller
{
    private const TABLE_KEY = 'clinical_alerts';

    public function __construct(private readonly ReferenceDataCacheService $referenceDataCacheService) {}

    public function index(): JsonResponse
    {
        $alerts = $this->referenceDataCacheService->remember(
            self::TABLE_KEY,
            [],
            fn (): array => config('clinical-alerts'),
        );

        return response()->json(['data' => $alerts]);
    }
}
