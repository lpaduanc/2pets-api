<?php

namespace App\Services\Insights\Widgets\Providers;

use App\Enums\SoldPackageLifecycleStatus;
use App\Enums\SoldPackageSessionStatus;
use App\Enums\SoldPackageStatus;
use App\Models\SoldPackage;
use App\Services\Insights\Widgets\DashboardWidgetContext;
use App\Support\Money\Money;

/**
 * Pacotes a vencer: pacotes vendidos com status derivado `expiring_soon` (2.7: ativos, com saldo,
 * validade ≤ hoje + `package_expiring_soon_days` da organização). Valor = receita diferida
 * (sessões a usar). Ordem: validade mais próxima. Domínio pacotes: `service-packages.view`.
 */
final class PackagesExpiringWidget extends BaseWidgetProvider
{
    private const LIST_LIMIT = 10;

    public function key(): string
    {
        return 'packages_expiring';
    }

    public function label(): string
    {
        return 'Pacotes a vencer';
    }

    public function description(): string
    {
        return 'Pacotes com saldo perto do vencimento.';
    }

    public function domain(): string
    {
        return 'packages';
    }

    public function permission(): string
    {
        return 'service-packages.view';
    }

    public function data(DashboardWidgetContext $context): array
    {
        $packages = $this->scoped(SoldPackage::query(), $context)
            ->whereEffectiveStatus(SoldPackageStatus::ACTIVE)
            ->whereNotNull('expires_at')
            ->with(['items', 'sessions', 'organization:id,package_expiring_soon_days', 'servicePackage:id,name', 'client:id,name', 'pet:id,name'])
            ->orderBy('expires_at')->orderBy('id')->get()
            ->filter(fn (SoldPackage $p): bool => $p->lifecycleStatus($p->organization?->package_expiring_soon_days ?? 30) === SoldPackageLifecycleStatus::EXPIRING_SOON)
            ->values();
        $deferred = fn (SoldPackage $p): Money => Money::sum($p->sessions->where('status', SoldPackageSessionStatus::PENDING)->pluck('amount'));

        return [
            'count' => $packages->count(),
            'deferred_total' => Money::sum($packages->map($deferred))->toString(),
            'items' => $packages->take(self::LIST_LIMIT)->map(fn (SoldPackage $p): array => [
                'id' => $p->id, 'package_name' => $p->servicePackage?->name, 'client_name' => $p->client?->name, 'pet_name' => $p->pet?->name,
                'expires_at' => $p->expires_at->toDateString(), 'days_left' => (int) $context->today->diffInDays($p->expires_at, false),
                'total_remaining' => $p->totalRemaining(), 'deferred_amount' => $deferred($p)->toString(),
            ])->all(),
        ];
    }
}
