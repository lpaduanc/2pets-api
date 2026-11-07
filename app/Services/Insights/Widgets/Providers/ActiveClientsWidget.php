<?php

namespace App\Services\Insights\Widgets\Providers;

use App\Enums\ClientLifecycleStage;
use App\Models\ClientRelationshipProfile;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Crm\ClientRelationshipProfileRecalculator;
use App\Services\Insights\Widgets\DashboardWidgetContext;

/**
 * Clientes ativos: perfis de relacionamento (não arquivados) cuja faixa do
 * `ClientLifecycleService` é de até 12 meses desde o último retorno — `returned_recently`,
 * `quiet_1_3m`, `quiet_3_6m`, `quiet_6_12m`. A partir de 1 ano a faixa já é "precisa de
 * atenção". Meta: parâmetro simples do widget (`goal`, 0 = sem meta), guardado no `config`;
 * com meta, `goal_percent` = ativos / meta × 100. Domínio clientes: `professional.clients.view`.
 */
final class ActiveClientsWidget extends BaseWidgetProvider
{
    /** @var list<ClientLifecycleStage> */
    public const ACTIVE_STAGES = [ClientLifecycleStage::RETURNED_RECENTLY, ClientLifecycleStage::QUIET_1_3M, ClientLifecycleStage::QUIET_3_6M, ClientLifecycleStage::QUIET_6_12M];

    public function __construct(CommercialScopeResolver $scope, private readonly ClientRelationshipProfileRecalculator $recalculator)
    {
        parent::__construct($scope);
    }

    public function key(): string
    {
        return 'active_clients';
    }

    public function label(): string
    {
        return 'Clientes ativos';
    }

    public function description(): string
    {
        return 'Clientes que voltaram nos últimos 12 meses.';
    }

    public function domain(): string
    {
        return 'clients';
    }

    public function permission(): string
    {
        return 'professional.clients.view';
    }

    public function parameters(): array
    {
        return ['goal' => ['type' => 'int', 'default' => 0, 'min' => 0, 'max' => 1000000]];
    }

    public function data(DashboardWidgetContext $context): array
    {
        $this->recalculator->ensureFreshFor($context->user);
        $counts = $this->scoped(ClientRelationshipProfile::query(), $context)->notArchived()
            ->groupBy('lifecycle_stage')->selectRaw('lifecycle_stage, COUNT(*) AS total')->pluck('total', 'lifecycle_stage');
        $active = (int) collect(self::ACTIVE_STAGES)->sum(fn (ClientLifecycleStage $stage): int => (int) ($counts[$stage->value] ?? 0));
        $goal = $context->int('goal');

        return [
            'active' => $active,
            'total' => (int) $counts->sum(),
            'by_stage' => collect(self::ACTIVE_STAGES)->map(fn (ClientLifecycleStage $stage): array => ['stage' => $stage->value, 'label' => $stage->label(), 'count' => (int) ($counts[$stage->value] ?? 0)])->all(),
            'goal' => $goal === 0 ? null : $goal,
            'goal_percent' => $goal === 0 ? null : (string) \Brick\Math\BigDecimal::of($active)->multipliedBy(100)->dividedBy($goal, 2, \Brick\Math\RoundingMode::HalfUp),
        ];
    }
}
