<?php

namespace App\Services\Insights\Widgets;

use App\Contracts\DashboardWidgetProvider;
use App\Models\User;
use App\Services\Commercial\Authorization\SalePermissionGate;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo dos widgets do painel (PLANO 3.6) — lista FECHADA de providers, no espírito do
 * `InsightIndicator` (sem motor genérico). Quem pode ver cada um: a permissão do provider pelo
 * cargo em TODA organização do escopo (`SalePermissionGate::allowsAcrossScope`, a mesma régua da
 * DRE gerencial), porque o dado agrega o escopo inteiro.
 */
final class DashboardWidgetRegistry
{
    /** @var list<class-string<DashboardWidgetProvider>> */
    public const PROVIDERS = [
        Providers\StockValuationWidget::class,
        Providers\ExpiringBatchesWidget::class,
        Providers\LowStockWidget::class,
        Providers\TopSuppliesWidget::class,
        Providers\SalesByCategoryWidget::class,
        Providers\AppointmentsScheduledCompletedWidget::class,
        Providers\ActiveClientsWidget::class,
        Providers\PayablesDueWidget::class,
        Providers\PackagesExpiringWidget::class,
        Providers\GrossProfitWidget::class,
        Providers\NetIncomeWidget::class,
        Providers\BreakEvenWidget::class,
        Providers\CostCenterRankingWidget::class,
        Providers\SalesGoalsWidget::class,
    ];

    public function __construct(private readonly SalePermissionGate $permissions) {}

    /** @return list<DashboardWidgetProvider> */
    public function all(): array
    {
        return array_map(fn (string $class): DashboardWidgetProvider => app($class), self::PROVIDERS);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(fn (DashboardWidgetProvider $p): string => $p->key(), $this->all());
    }

    public function find(string $key): ?DashboardWidgetProvider
    {
        return collect($this->all())->first(fn (DashboardWidgetProvider $p): bool => $p->key() === $key);
    }

    public function allows(User $user, DashboardWidgetProvider $provider): bool
    {
        return $this->permissions->allowsAcrossScope($user, $provider->permission());
    }

    /** @return list<DashboardWidgetProvider> */
    public function availableFor(User $user): array
    {
        return array_values(array_filter($this->all(), fn (DashboardWidgetProvider $p): bool => $this->allows($user, $p)));
    }

    /**
     * Parâmetros do widget validados contra a declaração do provider: inteiro dentro de
     * [min, max]; ausente = default; parâmetro desconhecido é ignorado.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolveParams(DashboardWidgetProvider $provider, array $input): array
    {
        $resolved = [];

        foreach ($provider->parameters() as $name => $spec) {
            $value = $input[$name] ?? $spec['default'];

            if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $spec['min'] || (int) $value > $spec['max']) {
                throw ValidationException::withMessages([$name => sprintf('Informe um número inteiro entre %d e %d.', $spec['min'], $spec['max'])]);
            }

            $resolved[$name] = (int) $value;
        }

        return $resolved;
    }

    /** @return array<string, mixed> */
    public function describe(DashboardWidgetProvider $provider): array
    {
        return [
            'key' => $provider->key(),
            'label' => $provider->label(),
            'description' => $provider->description(),
            'domain' => $provider->domain(),
            'permission' => $provider->permission(),
            'parameters' => $provider->parameters(),
        ];
    }
}
