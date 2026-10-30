<?php

namespace App\Services\Commercial\ServiceCost;

use App\Enums\StockMovementType;
use App\Models\Appointment;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\ServiceSupply;
use App\Models\StockMovement;
use App\Services\Stock\StockService;
use App\Support\Money\Decimal;
use App\Support\Money\Quantity;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Baixa dos insumos da ficha técnica quando o serviço é EXECUTADO — docs/gap-petflow/PLANO.md, 1.3.
 *
 * Regra de baixa (uma só por execução, nunca duas):
 *  - serviço de agendamento: baixa quando o agendamento vai para `completed`, referência =
 *    o `Appointment` (linhas de `appointment_services` + cobranças `/charges` com serviço; sem
 *    pivô, o `service_id` legado do agendamento do tutor). `completed` é terminal: não há estorno.
 *  - serviço vendido no PDV SEM agendamento: baixa quando a venda fica PAGA (mesmo momento do
 *    `sale_out` dos produtos), referência = o `SaleItem`. Cancelamento e devolução estornam
 *    (`return_in`) a parte correspondente, pelo valor com que saiu.
 *  - item de venda com `sale_items.appointment_id` (cobrança do atendimento no PDV, ou orçamento
 *    nascido do prontuário/internação e convertido): a venda NÃO baixa nem estorna — o insumo é
 *    do agendamento. Cada execução tem, assim, um único documento de origem.
 *
 * Idempotência (seção 3, item 10): a chave é (referência, `internal_use`) — havendo qualquer
 * movimento dessa chave, rodar de novo não baixa nada. Nunca bloqueia o ato: o `StockService`
 * aceita saldo negativo (docs/vinculo-estoque-aplicacao-clinica.md, item 1).
 *
 * Quantidade baixada = quantidade da ficha (3 casas) × execuções; o produto pode passar das 4
 * casas do estoque (0,333 × 1,5) e então arredonda UMA vez, HALF_UP, para `Quantity::SCALE`.
 */
final class ServiceSupplyConsumer
{
    public function __construct(private readonly StockService $stock) {}

    public function consumeForAppointment(Appointment $appointment, ?int $userId): void
    {
        DB::transaction(function () use ($appointment, $userId): void {
            if (! $this->claim($appointment)) {
                return;
            }

            foreach ($this->appointmentExecutions($appointment) as [$service, $times]) {
                $this->consume($service, $times, $appointment, [
                    'user' => $userId,
                    'notes' => 'Insumo do atendimento #'.$appointment->id,
                ]);
            }
        });
    }

    /** @param  array<string, mixed>  $context  `user`, `occurred_at`, `notes` */
    public function consumeForSaleItem(SaleItem $item, array $context): void
    {
        if (! $item->sellable instanceof Service || $item->appointment_id !== null) {
            return;
        }

        DB::transaction(function () use ($item, $context): void {
            if ($this->claim($item)) {
                $this->consume($item->sellable, Quantity::of($item->quantity), $item, $context);
            }
        });
    }

    /**
     * Estorno do cancelamento/devolução: com `$returnedTotal` execuções devolvidas no acumulado
     * (contando esta), volta ao estoque a parte delas no que saiu, por produto. O acumulado faz
     * devolver tudo em várias vezes repor exatamente o consumido — sem resíduo.
     *
     * @param  array<string, mixed>  $context  `user`, `notes`
     */
    public function reverseForSaleItem(SaleItem $item, Quantity $returnedTotal, array $context): void
    {
        // Item ligado a agendamento nunca baixou pela venda: cancelar a venda não devolve o que
        // o atendimento consumiu.
        if (! $item->sellable instanceof Service || $item->appointment_id !== null) {
            return;
        }

        foreach ($this->consumedByProduct($item) as $productId => $consumed) {
            $this->restoreProduct($item, $productId, $this->targetBack($consumed, $returnedTotal, Quantity::of($item->quantity)), $context);
        }
    }

    /**
     * Trava o documento de origem e diz se ainda não houve baixa dele.
     */
    private function claim(Model $reference): bool
    {
        $reference->newQuery()->whereKey($reference->getKey())->lockForUpdate()->first();

        return ! $this->movements($reference, StockMovementType::INTERNAL_USE)->exists();
    }

    /** @param  array<string, mixed>  $context */
    private function consume(Service $service, Quantity $times, Model $reference, array $context): void
    {
        $supplies = $service->supplies()->with('product')->orderBy('product_id')->get();

        foreach ($supplies as $supply) {
            $quantity = self::scaled($supply, $times);

            if ($supply->product === null || ! $quantity->isPositive()) {
                continue;
            }

            $this->stock->out($supply->product, StockMovementType::INTERNAL_USE, $quantity, $context + ['reference' => $reference]);
        }
    }

    /**
     * Serviços executados no agendamento — os mesmos que a fatura cobra (`AppointmentInvoiceService`).
     *
     * @return list<array{0: Service, 1: Quantity}>
     */
    private function appointmentExecutions(Appointment $appointment): array
    {
        $appointment->load(['services.service', 'charges.service', 'service']);
        $lines = $appointment->services->concat($appointment->charges)
            ->filter(fn (Model $line): bool => $line->getRelation('service') instanceof Service)
            ->map(fn (Model $line): array => [$line->getRelation('service'), Quantity::of($line->quantity)]);

        if ($appointment->services->isEmpty() && $appointment->service instanceof Service) {
            $lines->push([$appointment->service, Quantity::of(1)]);
        }

        return $lines->values()->all();
    }

    /** @return array<int, Quantity> */
    private function consumedByProduct(SaleItem $item): array
    {
        return $this->movements($item, StockMovementType::INTERNAL_USE)
            ->selectRaw('product_id, SUM(quantity) AS consumed')
            ->groupBy('product_id')
            ->pluck('consumed', 'product_id')
            ->map(fn (string|int|float $consumed): Quantity => Quantity::of((string) $consumed))
            ->all();
    }

    private function targetBack(Quantity $consumed, Quantity $returnedTotal, Quantity $executions): Quantity
    {
        if ($returnedTotal->isGreaterThanOrEqualTo($executions)) {
            return $consumed;
        }

        return Quantity::of($consumed->toBigDecimal()->multipliedBy($returnedTotal->toBigDecimal())
            ->dividedBy($executions->toBigDecimal(), Quantity::SCALE, RoundingMode::HalfUp));
    }

    /** @param  array<string, mixed>  $context */
    private function restoreProduct(SaleItem $item, int $productId, Quantity $target, array $context): void
    {
        $alreadyBack = Quantity::of($this->movements($item, StockMovementType::RETURN_IN)->where('product_id', $productId)->sum('quantity'));
        $quantity = $target->minus($alreadyBack);
        $product = Product::withTrashed()->find($productId);

        if ($product === null || ! $quantity->isPositive()) {
            return;
        }

        $this->stock->in($product, StockMovementType::RETURN_IN, $quantity, $context + [
            'total_cost' => $this->stock->returningValue($product, $item, StockMovementType::INTERNAL_USE, $alreadyBack, $quantity),
            'reference' => $item,
        ]);
    }

    /** @return Builder<StockMovement> */
    private function movements(Model $reference, StockMovementType $type): Builder
    {
        return StockMovement::query()
            ->where('reference_type', $reference->getMorphClass())
            ->where('reference_id', $reference->getKey())
            ->where('type', $type->value);
    }

    private static function scaled(ServiceSupply $supply, Quantity $times): Quantity
    {
        return Quantity::of(Decimal::of($supply->quantity)->multipliedBy($times->toBigDecimal())
            ->toScale(Quantity::SCALE, RoundingMode::HalfUp));
    }
}
