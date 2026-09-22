<?php

namespace App\Http\Controllers\Api;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Resources\Commercial\QuoteResource;
use App\Http\Resources\Commercial\TutorQuoteResource;
use App\Models\Pet;
use App\Models\Sale;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `pets/{pet}/quotes` — aba "Orçamentos" da ficha do animal (docs/gap-simplesvet/24-orcamentos.md).
 *
 * Duas audiências, como `pets/{pet}/invoices`: o tutor dono vê os orçamentos enviados para
 * aquele animal, de qualquer clínica; o profissional vê os do SEU escopo comercial — orçamento
 * é documento comercial da clínica, e ter acesso clínico ao pet (PetVetAccess) não dá direito a
 * ver quanto outra clínica cobrou.
 */
class PetQuotesController extends Controller
{
    use PaginatesResults;

    private const DEFAULT_PER_PAGE = 20;

    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function __invoke(Request $request, int $pet): AnonymousResourceCollection
    {
        $petModel = Pet::findOrFail($pet);
        $user = $request->user();
        $perPage = $this->resolvePerPage($request, self::DEFAULT_PER_PAGE);

        if ($petModel->user_id === $user->id) {
            return TutorQuoteResource::collection(
                Sale::query()->quotesOnly()
                    ->where('pet_id', $petModel->id)
                    ->where('client_id', $user->id)
                    ->where('quote_status', '!=', QuoteStatus::DRAFT->value)
                    ->with(TutorQuoteResource::RELATIONS)
                    ->orderByDesc('created_at')
                    ->paginate($perPage)
            );
        }

        return QuoteResource::collection(
            $this->scope->scopeQuery(Sale::query()->quotesOnly(), $user)
                ->where('pet_id', $petModel->id)
                ->with(QuoteResource::RELATIONS)
                ->orderByDesc('created_at')
                ->paginate($perPage)
        );
    }
}
