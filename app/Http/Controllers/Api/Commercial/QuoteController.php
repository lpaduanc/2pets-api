<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\DiscountType;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StoreQuoteRequest;
use App\Http\Requests\Commercial\StoreSaleItemRequest;
use App\Http\Requests\Commercial\UpdateQuoteRequest;
use App\Http\Resources\Commercial\QuoteResource;
use App\Http\Resources\Commercial\SaleItemResource;
use App\Http\Resources\Commercial\SaleResource;
use App\Models\Hospitalization;
use App\Models\MedicalRecord;
use App\Models\Sale;
use App\Models\User;
use App\Notifications\Support\FrontendRoute;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\QuoteService;
use App\Services\Commercial\SaleService;
use App\Services\Report\QuotePdfService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Orçamentos na visão da clínica — docs/gap-simplesvet/24-orcamentos.md.
 *
 * Itens e desconto passam pelo MESMO `SaleService` do PDV (orçamento é `sales.kind = quote`);
 * o ciclo de envio/revisão/conversão é do `QuoteService`. Escopo por organização via
 * `CommercialScopeResolver`, autorização em `SalePolicy` — nenhuma regra aqui.
 *
 * Itens e cabeçalho autorizam por `manageQuote` (escopo), não por `update`: orçamento fora de
 * rascunho tem que responder 422 `quote_not_editable` ("crie uma revisão"), e `update` negaria
 * com 403 antes de o service explicar o porquê.
 */
class QuoteController extends Controller
{
    use PaginatesResults;

    private const DEFAULT_PER_PAGE = 30;

    public function __construct(
        private readonly QuoteService $quotes,
        private readonly SaleService $sales,
        private readonly CommercialScopeResolver $scope,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->scopedQuery($request->user())->with(QuoteResource::RELATIONS);
        $this->applyFilters($query, $request);

        return QuoteResource::collection(
            $query->orderByDesc('created_at')
                ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE))
                ->withQueryString()
        );
    }

    public function store(StoreQuoteRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if (! empty($data['medical_record_id'])) {
            $record = MedicalRecord::findOrFail($data['medical_record_id']);
            Gate::forUser($user)->authorize('view', $record);
            $quote = $this->quotes->createFromRecord($record, $user, $data);
        } elseif (! empty($data['hospitalization_id'])) {
            $hospitalization = Hospitalization::findOrFail($data['hospitalization_id']);
            Gate::forUser($user)->authorize('view', $hospitalization);
            $quote = $this->quotes->createFromHospitalization($hospitalization, $user, $data);
        } else {
            $quote = $this->quotes->create($user, $data);
        }

        return $this->respond($quote, 'Orçamento criado.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($this->findForUser($request->user(), $id, 'view'));
    }

    public function update(UpdateQuoteRequest $request, int $id): JsonResponse
    {
        $quote = $this->findForUser($request->user(), $id, 'manageQuote');

        return $this->respond($this->quotes->updateHeader($quote, $request->user(), $request->validated()), 'Orçamento atualizado.');
    }

    public function storeItem(StoreSaleItemRequest $request, int $id): JsonResponse
    {
        $quote = $this->findForUser($request->user(), $id, 'manageQuote');
        $item = $this->sales->addItem($quote, $request->user(), $request->validated());

        return (new SaleItemResource($item))
            ->additional(['message' => 'Item adicionado.', 'quote' => $this->resource($quote)])
            ->response()
            ->setStatusCode(201);
    }

    public function destroyItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $quote = $this->findForUser($request->user(), $id, 'manageQuote');
        $this->sales->removeItem($quote, $itemId);

        return response()->json(['message' => 'Item removido.', 'quote' => $this->resource($quote)]);
    }

    public function applyDiscount(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'discount_type' => ['required', Rule::enum(DiscountType::class)],
            'discount_value' => ['required_unless:discount_type,none', 'nullable', 'numeric', 'min:0'],
        ]);

        $quote = $this->findForUser($request->user(), $id, 'manageQuote');
        $this->sales->applyDiscount(
            $quote,
            DiscountType::from($request->string('discount_type')->toString()),
            (float) $request->input('discount_value', 0),
        );

        return $this->respond($quote);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $quote = $this->findForUser($request->user(), $id, 'manageQuote');
        ['quote' => $quote, 'token' => $token] = $this->quotes->send($quote, $request->user());

        return response()->json([
            'data' => $this->resource($quote),
            // Única vez que o link sai em texto puro — o banco só tem o hash.
            'public_url' => FrontendRoute::absolute(FrontendRoute::publicQuoteDecision($token)),
            'message' => 'Orçamento enviado ao tutor.',
        ]);
    }

    public function revise(Request $request, int $id): JsonResponse
    {
        $quote = $this->findForUser($request->user(), $id, 'manageQuote');

        return $this->respond($this->quotes->revise($quote, $request->user()), 'Nova versão criada.', 201);
    }

    public function convert(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'receipts' => ['nullable', 'array', 'max:10'],
            'receipts.*.payment_method_id' => ['required', 'integer'],
            'receipts.*.amount' => ['required', 'numeric', 'gt:0'],
            'receipts.*.installments' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        $quote = $this->findForUser($request->user(), $id, 'manageQuote');
        $sale = $this->quotes->convert($quote, $request->user(), $data['receipts'] ?? []);

        return (new SaleResource($sale))
            ->additional(['message' => 'Orçamento convertido em venda.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PDF da versão. O arquivo gravado no envio é o documento que o tutor recebeu; rascunho
     * (ou arquivo perdido) é renderizado na hora, sem gravar.
     */
    public function pdf(Request $request, int $id, QuotePdfService $pdf): Response
    {
        $quote = $this->findForUser($request->user(), $id, 'manageQuote');
        $disk = Storage::disk('local');

        $content = $quote->pdf_path !== null && $disk->exists($quote->pdf_path)
            ? $disk->get($quote->pdf_path)
            : $pdf->render($quote);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($quote).'"',
        ]);
    }

    // ------------------------------------------------------------------

    private function respond(Sale $quote, ?string $message = null, int $status = 200): JsonResponse
    {
        $payload = ['data' => $this->resource($quote)];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json($payload, $status);
    }

    /** Recarrega com as relações e a família de versões — é o que o `show` devolve sempre. */
    private function resource(Sale $quote): QuoteResource
    {
        $quote = $quote->fresh(QuoteResource::RELATIONS);
        $quote->setRelation('familyVersions', $this->quotes->versionsOf($quote));

        return new QuoteResource($quote);
    }

    private function findForUser(User $user, int $id, string $ability): Sale
    {
        $quote = $this->scopedQuery($user)->with(QuoteResource::RELATIONS)->findOrFail($id);

        Gate::forUser($user)->authorize($ability, $quote);

        return $quote;
    }

    /**
     * @return Builder<Sale>
     */
    private function scopedQuery(User $user): Builder
    {
        return $this->scope->scopeQuery(Sale::query()->quotesOnly(), $user);
    }

    /**
     * Filtros da lista de orçamentos (doc 24): status efetivo, período, cliente, animal,
     * validade e origem clínica.
     *
     * @param  Builder<Sale>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        // `status` aceita um valor ou uma lista (`status[]=sent&status[]=viewed`); valor
        // desconhecido é ignorado em vez de virar 500 no `from()`.
        $statuses = array_values(array_filter(array_map(
            fn ($value) => is_string($value) ? QuoteStatus::tryFrom($value) : null,
            (array) $request->input('status', [])
        )));

        $query
            ->when($statuses !== [], fn (Builder $q) => $q->where(function (Builder $any) use ($statuses): void {
                foreach ($statuses as $status) {
                    $any->orWhere(fn (Builder $one) => $one->whereQuoteStatus($status));
                }
            }))
            ->when($request->filled('client_id'), fn (Builder $q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('pet_id'), fn (Builder $q) => $q->where('pet_id', $request->integer('pet_id')))
            ->when($request->filled('medical_record_id'), fn (Builder $q) => $q->where('medical_record_id', $request->integer('medical_record_id')))
            ->when($request->filled('hospitalization_id'), fn (Builder $q) => $q->where('hospitalization_id', $request->integer('hospitalization_id')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->when($request->filled('valid_from'), fn (Builder $q) => $q->whereDate('valid_until', '>=', $request->date('valid_from')))
            ->when($request->filled('valid_to'), fn (Builder $q) => $q->whereDate('valid_until', '<=', $request->date('valid_to')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = $request->string('search')->toString();
                $q->where(function (Builder $inner) use ($term): void {
                    if (ctype_digit($term)) {
                        $inner->where('number', (int) $term);
                    }

                    $inner->orWhereHas('client', fn (Builder $c) => $c->where('name', 'ilike', "%{$term}%"))
                        ->orWhereHas('pet', fn (Builder $p) => $p->where('name', 'ilike', "%{$term}%"));
                });
            });
    }
}
