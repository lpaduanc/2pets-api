<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\Commercial\PublicQuoteResource;
use App\Services\Commercial\QuotePublicTokenService;
use App\Services\Commercial\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Link de aprovação sem login — docs/gap-simplesvet/24-orcamentos.md, "Segurança".
 *
 * Token inválido é 404 (nunca dizemos se o orçamento existe); token usado ou vencido é 410.
 * O payload é `PublicQuoteResource`, lista branca sem nada clínico. Rate limit na rota.
 */
class QuoteDecisionController extends Controller
{
    public function __construct(
        private readonly QuoteService $quotes,
        private readonly QuotePublicTokenService $tokens,
    ) {}

    public function show(string $token): JsonResponse
    {
        $quote = $this->tokens->find($token);
        abort_if($quote === null, 404, 'Link de orçamento inválido.');

        $this->quotes->assertPublicLinkUsable($quote);
        $this->quotes->markViewed($quote);

        return response()->json(['data' => new PublicQuoteResource($quote->fresh(PublicQuoteResource::RELATIONS))]);
    }

    public function decide(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $approved = $data['decision'] === 'approve';

        $quote = $this->quotes->decideByToken(
            $token,
            $approved,
            $data['reason'] ?? null,
            $request->ip(),
            $request->userAgent(),
        );

        abort_if($quote === null, 404, 'Link de orçamento inválido.');

        return response()->json([
            'data' => new PublicQuoteResource($quote->fresh(PublicQuoteResource::RELATIONS)),
            'message' => $approved ? 'Orçamento aprovado. A clínica foi avisada.' : 'Orçamento recusado. A clínica foi avisada.',
        ]);
    }
}
