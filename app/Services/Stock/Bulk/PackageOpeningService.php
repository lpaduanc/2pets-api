<?php

namespace App\Services\Stock\Bulk;

use App\Enums\StockMovementType;
use App\Models\PackageOpening;
use App\Models\Product;
use App\Models\ProductConversion;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Stock\StockService;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Abertura de embalagem (docs/gap-petflow/PLANO.md, 3.3), numa transação só:
 *
 *  1. sai `q` do saco (`package_open_out`), FEFO por lote, pelo custo médio;
 *  2. para CADA pedaço que saiu (um por lote), entra `pedaço × fator` no granel
 *     (`package_open_in`) com o VALOR exato daquele pedaço — o lote e a validade do saco viram o
 *     lote do granel (o granel precisa controlar lote para guardá-lo);
 *  3. o custo médio do granel é refeito pelo `AverageCostCalculator` (dentro do `StockService`).
 *
 * Abrir mais do que há no saco é recusado (422): diferente da venda, abertura não pode deixar
 * estoque negativo — seria granel nascendo de saco que não existe.
 */
final class PackageOpeningService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly CommercialScopeResolver $scope,
    ) {}

    public function open(User $user, int $fromProductId, ?int $toProductId, string $quantity, ?string $notes = null): PackageOpening
    {
        $conversion = $this->conversionFor($user, $fromProductId, $toProductId);
        $quantity = Quantity::of($quantity);

        try {
            $entered = PackageOpeningCalculator::entered($quantity, (string) $conversion->factor);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['quantity' => 'A quantidade aberta × fator passa de 4 casas decimais.']);
        }

        if (! $conversion->fromProduct->acceptsQuantity($quantity)) {
            throw ValidationException::withMessages(['quantity' => sprintf('"%s" aceita até %d casa(s) decimal(is).', $conversion->fromProduct->name, $conversion->fromProduct->decimal_places_allowed)]);
        }

        return DB::transaction(function () use ($user, $conversion, $quantity, $entered, $notes): PackageOpening {
            $from = Product::query()->whereKey($conversion->from_product_id)->lockForUpdate()->firstOrFail();

            if (Quantity::of($from->stock_quantity)->isLessThan($quantity)) {
                throw ValidationException::withMessages(['quantity' => sprintf('Estoque insuficiente de "%s": há %s.', $from->name, Quantity::of($from->stock_quantity)->toBigDecimal()->strippedOfTrailingZeros())]);
            }

            $opening = PackageOpening::create([
                'product_conversion_id' => $conversion->id,
                'from_product_id' => $conversion->from_product_id,
                'to_product_id' => $conversion->to_product_id,
                'quantity' => $quantity->toString(),
                'factor' => (string) $conversion->factor,
                'entered_quantity' => $entered->toString(),
                'total_cost' => '0.00',
                'user_id' => $user->id,
                'notes' => $notes,
            ] + $this->scope->ownershipFor($user));

            $context = ['reference' => $opening, 'user' => $user, 'notes' => $notes];
            $outs = $this->stock->out($from, StockMovementType::PACKAGE_OPEN_OUT, $quantity, $context);
            $to = $conversion->toProduct;

            foreach ($outs as $out) {
                $out->loadMissing('batch');
                $this->stock->in($to, StockMovementType::PACKAGE_OPEN_IN, PackageOpeningCalculator::entered(Quantity::of($out->quantity), (string) $conversion->factor), $context + [
                    'total_cost' => (string) $out->total_cost,
                    'batch_code' => $out->batch?->batch_code,
                    'expires_at' => $out->batch?->expires_at?->toDateString(),
                ]);
            }

            $opening->update(['total_cost' => Money::sum(array_map(fn (StockMovement $m): string => (string) $m->total_cost, $outs))->toString()]);

            return $opening->fresh(['fromProduct', 'toProduct']);
        });
    }

    private function conversionFor(User $user, int $fromProductId, ?int $toProductId): ProductConversion
    {
        $conversions = $this->scope->scopeQuery(ProductConversion::query(), $user)
            ->where('from_product_id', $fromProductId)
            ->when($toProductId !== null, fn ($q) => $q->where('to_product_id', $toProductId))
            ->with(['fromProduct', 'toProduct'])
            ->get();

        if ($conversions->isEmpty()) {
            throw ValidationException::withMessages(['from_product_id' => 'Este produto não tem conversão para granel cadastrada.']);
        }

        if ($conversions->count() > 1) {
            throw ValidationException::withMessages(['to_product_id' => 'Este produto tem mais de uma conversão: informe o produto de destino.']);
        }

        return $conversions->first();
    }
}
