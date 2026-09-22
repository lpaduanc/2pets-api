<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\Document\DocumentVerificationService;
use Illuminate\Http\JsonResponse;

/**
 * `GET public/documents/verify/{code}` — sem autenticação, rate-limited. Contrato
 * docs/gap-simplesvet/specs/15-modelos-documento-receituario-assinatura-spec.md, regra 6.
 */
class DocumentVerificationController extends Controller
{
    public function __construct(private readonly DocumentVerificationService $verificationService) {}

    public function __invoke(string $code): JsonResponse
    {
        return response()->json(['data' => $this->verificationService->verify($code)]);
    }
}
