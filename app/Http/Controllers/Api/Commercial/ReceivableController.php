<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\CardBrand;
use App\Enums\ReceivableStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Commercial\ReceivableResource;
use App\Models\Receivable;
use App\Services\Commercial\CommercialScopeResolver;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Agenda de recebíveis de cartão — docs/gap-petflow/PLANO.md, 1.1. O resumo soma o período
 * FILTRADO inteiro (não só a página), em centavos exatos.
 */
class ReceivableController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(ReceivableStatus::values())],
            'card_brand' => ['nullable', Rule::in(CardBrand::values())],
            'payment_method_id' => ['nullable', 'integer'],
        ]);

        $query = $this->filtered($request, $filters);
        $rows = (clone $query)->get(['gross_amount', 'fee_amount', 'net_amount']);

        return ReceivableResource::collection(
            $query->with(['paymentMethod:id,name', 'receipt.sale:id,number'])->orderBy('expected_date')->orderBy('id')->paginate(self::PER_PAGE)
        )->additional(['summary' => [
            'gross_amount' => Money::sum($rows->pluck('gross_amount'))->toString(),
            'fee_amount' => Money::sum($rows->pluck('fee_amount'))->toString(),
            'net_amount' => Money::sum($rows->pluck('net_amount'))->toString(),
        ]]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Receivable>
     */
    private function filtered(Request $request, array $filters): Builder
    {
        return $this->scope->scopeQuery(Receivable::query(), $request->user())
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('expected_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('expected_date', '<=', $to))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['card_brand'] ?? null, fn ($q, $brand) => $q->where('card_brand', $brand))
            ->when($filters['payment_method_id'] ?? null, fn ($q, $id) => $q->where('payment_method_id', $id));
    }
}
