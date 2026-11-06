<?php

namespace App\Services\Commercial\Package;

use App\Enums\SoldPackageSessionStatus;
use App\Models\SoldPackage;
use App\Models\SoldPackageSession;
use App\Support\Money\Money;
use Brick\Math\RoundingMode;

/**
 * Rentabilidade sessão a sessão do pacote vendido (PLANO 2.7), pela mesma conta da 1.5 sobre o
 * VALOR DA SESSÃO no pacote (nunca o preço avulso):
 *
 *   receita = valor da sessão;  custo = custo do serviço na baixa (2 casas HALF_UP)
 *   taxa e imposto = parte da sessão nos da linha do pacote (`PackageSessionEconomics::lineShares`)
 *   comissão = a do fechamento, senão a calculada para quem executou
 *   MC = receita − custo − taxa − imposto − comissão;  MC% = MC / receita (null se receita 0)
 *
 * Só sessões usadas entram nas linhas; o resumo mostra também o que ainda é receita diferida e o
 * que venceu (crédito/receita de expiração).
 */
final class SoldPackageProfitability
{
    public function __construct(private readonly PackageSessionEconomics $economics) {}

    /** @return array<string, mixed> */
    public function report(SoldPackage $package): array
    {
        $package->load(['sessions.soldPackageItem.service', 'sessions.consumption.appointment', 'sessions.commissionSettlementItem',
            'saleItem', 'servicePackage', 'sale.items', 'sale.receipts', 'sale.organization']);
        $package->sessions->each(fn (SoldPackageSession $session) => $session->setRelation('soldPackage', $package));
        $shares = $this->economics->lineShares($package);

        $rows = $package->sessions->where('status', SoldPackageSessionStatus::CONSUMED)->values()
            ->map(fn (SoldPackageSession $session): array => $this->row($session, $shares[$session->id] ?? ['card_fee' => Money::zero(), 'tax' => Money::zero()]));

        $sum = fn (string $key): Money => Money::sum($rows->pluck($key));

        return [
            'sold_package_id' => $package->id,
            'package_price' => (string) $package->package_price,
            'sessions' => $rows->map(fn (array $row): array => array_map(fn ($value) => $value instanceof Money ? $value->toString() : $value, $row))->all(),
            'totals' => [
                'revenue' => $sum('revenue')->toString(),
                'cost_amount' => $sum('cost_amount')->toString(),
                'card_fee_share' => $sum('card_fee_share')->toString(),
                'tax_amount' => $sum('tax_amount')->toString(),
                'commission_amount' => $sum('commission_amount')->toString(),
                'contribution_margin' => $sum('contribution_margin')->toString(),
                'margin_percent' => self::percent($sum('contribution_margin'), $sum('revenue')),
            ],
            'balance' => [
                'deferred' => $this->sumStatus($package, SoldPackageSessionStatus::PENDING),
                'expired_revenue' => $this->sumStatus($package, SoldPackageSessionStatus::EXPIRED),
                'credited' => $this->sumStatus($package, SoldPackageSessionStatus::CREDITED),
                'cancelled' => $this->sumStatus($package, SoldPackageSessionStatus::CANCELLED),
            ],
        ];
    }

    /**
     * @param  array{card_fee: Money, tax: Money}  $share
     * @return array<string, mixed>
     */
    private function row(SoldPackageSession $session, array $share): array
    {
        $revenue = Money::of($session->amount);
        $cost = Money::of($session->unit_cost ?? '0');
        $commission = $this->economics->commissionForProfitability($session, $share['card_fee']);
        $margin = $revenue->minus($cost)->minus($share['card_fee'])->minus($share['tax'])->minus($commission);

        return [
            'session_id' => $session->id,
            'description' => $session->description(),
            'consumed_at' => $session->consumed_at?->toIso8601String(),
            'staff_id' => $session->staff_id,
            'revenue' => $revenue,
            'cost_amount' => $cost,
            'card_fee_share' => $share['card_fee'],
            'tax_amount' => $share['tax'],
            'commission_amount' => $commission,
            'contribution_margin' => $margin,
            'margin_percent' => self::percent($margin, $revenue),
        ];
    }

    private function sumStatus(SoldPackage $package, SoldPackageSessionStatus $status): string
    {
        return Money::sum($package->sessions->where('status', $status)->pluck('amount'))->toString();
    }

    private static function percent(Money $margin, Money $revenue): ?string
    {
        if (! $revenue->isPositive()) {
            return null;
        }

        return (string) $margin->toBigDecimal()->multipliedBy(100)->dividedBy($revenue->toBigDecimal(), 2, RoundingMode::HalfUp);
    }
}
