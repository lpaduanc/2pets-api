<?php

namespace App\Http\Controllers\Api;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Resources\Commercial\TutorQuoteResource;
use App\Models\Sale;
use App\Services\Commercial\QuoteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `me/quotes` — orçamentos recebidos pelo tutor logado (docs/gap-simplesvet/24-orcamentos.md).
 *
 * O dono do orçamento é o `client_id`; o controller nunca aceita id de outra pessoa (404, não
 * 403: não confirmamos a existência de orçamento alheio). Rascunho nunca aparece — ainda não
 * foi enviado, é documento interno da clínica.
 */
class TutorQuoteController extends Controller
{
    use PaginatesResults;

    private const DEFAULT_PER_PAGE = 20;

    public function __construct(private readonly QuoteService $quotes) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->ownQuotes($request)
            ->with(TutorQuoteResource::RELATIONS)
            ->when($request->boolean('pending'), fn (Builder $q) => $q->where(fn (Builder $any) => $any
                ->whereQuoteStatus(QuoteStatus::SENT)
                ->orWhere(fn (Builder $viewed) => $viewed->whereQuoteStatus(QuoteStatus::VIEWED))));

        return TutorQuoteResource::collection(
            $query->orderByDesc('sent_at')->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE))
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $quote = $this->find($request, $id);
        $this->quotes->markViewed($quote);

        return $this->respond($quote);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $quote = $this->quotes->approve($this->find($request, $id), 'app', $request->ip(), $request->userAgent());

        return $this->respond($quote, 'Orçamento aprovado. A clínica foi avisada.');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        $quote = $this->quotes->reject(
            $this->find($request, $id),
            $data['reason'] ?? null,
            'app',
            $request->ip(),
            $request->userAgent(),
        );

        return $this->respond($quote, 'Orçamento recusado. A clínica foi avisada.');
    }

    /**
     * @return Builder<Sale>
     */
    private function ownQuotes(Request $request): Builder
    {
        return Sale::query()
            ->quotesOnly()
            ->where('client_id', $request->user()->id)
            ->where('quote_status', '!=', QuoteStatus::DRAFT->value);
    }

    private function find(Request $request, int $id): Sale
    {
        return $this->ownQuotes($request)->findOrFail($id);
    }

    private function respond(Sale $quote, ?string $message = null): JsonResponse
    {
        $quote = $quote->fresh(TutorQuoteResource::RELATIONS);
        // Comparação entre versões: só as que o tutor já recebeu (sem rascunho da próxima).
        $quote->setRelation('familyVersions', $this->quotes->versionsOf($quote, ['items'])
            ->reject(fn (Sale $version) => $version->quote_status === QuoteStatus::DRAFT)
            ->values());

        return response()->json(array_filter([
            'data' => new TutorQuoteResource($quote),
            'message' => $message,
        ]));
    }
}
