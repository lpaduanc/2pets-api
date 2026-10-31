<?php

namespace App\Services\Commercial\Pricing;

use App\Enums\CardFeeReferenceMode;
use App\Enums\PaymentMethodKind;
use App\Enums\ReceivableStatus;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodFeeTier;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Support\Money\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Taxa de cartão de referência do preço mínimo — docs/gap-petflow/PLANO.md, 1.4 (decisão 2).
 *
 * - `weighted_90d` (padrão): Σ taxa ÷ Σ bruto das parcelas de cartão (`receivables`) cujo
 *   recebimento caiu nos últimos 90 dias — o recorte começa à meia-noite de Brasília de 90 dias
 *   atrás. Razão das somas, nunca média de percentuais (seção 3, item 5). A taxa da parcela já
 *   inclui a tarifa fixa, então a média é a taxa EFETIVA paga. Parcela cancelada fica de fora.
 * - `worst_case`: o maior MDR ativo cadastrado — faixa vigente hoje; forma de cartão ativa sem
 *   faixa conta pelo `fee_percent` dela (é a taxa que o `FeeTierResolver` aplicaria).
 *
 * Sem histórico de 90 dias, a média cai para o pior caso; sem taxa nenhuma, `percent` é `null`.
 */
final class CardFeeReferenceResolver
{
    public const TIMEZONE = 'America/Sao_Paulo';

    public const WINDOW_DAYS = 90;

    private const PERCENT_SCALE = 4;

    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function resolve(User $user, ?Organization $organization): CardFeeReference
    {
        $mode = $organization?->card_fee_reference_mode ?? CardFeeReferenceMode::WEIGHTED_90D;

        if ($mode === CardFeeReferenceMode::WEIGHTED_90D) {
            $weighted = $this->weightedAverage($user);

            if ($weighted !== null) {
                return new CardFeeReference(CardFeeReferenceMode::WEIGHTED_90D, $weighted);
            }
        }

        return new CardFeeReference(CardFeeReferenceMode::WORST_CASE, $this->worstCase($user));
    }

    private function weightedAverage(User $user): ?string
    {
        $since = CarbonImmutable::now(self::TIMEZONE)->startOfDay()->subDays(self::WINDOW_DAYS)->utc();
        $totals = $this->scope->scopeQuery(Receivable::query(), $user)
            ->join('sale_receipts', 'sale_receipts.id', '=', 'receivables.sale_receipt_id')
            ->where('sale_receipts.received_at', '>=', $since)
            ->where('receivables.status', '!=', ReceivableStatus::CANCELLED->value)
            ->selectRaw('COALESCE(SUM(receivables.fee_amount), 0) AS fee, COALESCE(SUM(receivables.gross_amount), 0) AS gross')
            ->toBase()
            ->first();
        $gross = Decimal::of((string) $totals->gross);

        if (! $gross->isPositive()) {
            return null;
        }

        return (string) Decimal::of((string) $totals->fee)->multipliedBy(100)->dividedBy($gross, self::PERCENT_SCALE, RoundingMode::HalfUp);
    }

    private function worstCase(User $user): ?string
    {
        $methods = $this->cardMethods($user);
        $rates = $methods->flatMap(fn (PaymentMethod $method): array => $this->ratesOf($method))
            ->filter(fn (BigDecimal $rate): bool => $rate->isPositive());

        if ($rates->isEmpty()) {
            return null;
        }

        return (string) BigDecimal::max(...$rates->all())->toScale(self::PERCENT_SCALE);
    }

    /** @return list<string> */
    private static function cardKinds(): array
    {
        return array_values(array_map(
            fn (PaymentMethodKind $kind): string => $kind->value,
            array_filter(PaymentMethodKind::cases(), fn (PaymentMethodKind $kind): bool => $kind->settlesThroughAcquirer()),
        ));
    }

    /** @return Collection<int, PaymentMethod> */
    private function cardMethods(User $user): Collection
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->toDateString();

        return $this->scope->scopeQuery(PaymentMethod::query(), $user)
            ->active()
            ->whereIn('kind', self::cardKinds())
            ->withCount('feeTiers')
            ->with(['feeTiers' => fn ($tiers) => $tiers
                ->where(fn (Builder $from) => $from->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
                ->where(fn (Builder $to) => $to->whereNull('valid_to')->orWhere('valid_to', '>=', $today))])
            ->get();
    }

    /**
     * Forma com faixas cadastradas e nenhuma vigente não tem taxa a oferecer (o recebimento seria
     * recusado pelo `FeeTierResolver`); só a forma SEM faixa nenhuma conta pela taxa base.
     *
     * @return list<BigDecimal>
     */
    private function ratesOf(PaymentMethod $method): array
    {
        if ($method->fee_tiers_count === 0) {
            return [Decimal::of((string) $method->fee_percent)];
        }

        return $method->feeTiers->map(fn (PaymentMethodFeeTier $tier): BigDecimal => Decimal::of((string) $tier->fee_percent))->all();
    }
}
