<?php

namespace App\Services\Insights\Widgets\Providers;

use App\Enums\FinancialEntryStatus;
use App\Enums\FinancialNature;
use App\Models\FinancialEntry;
use App\Services\Insights\Widgets\DashboardWidgetContext;
use App\Support\Money\Money;

/**
 * Contas a vencer: lançamentos de DESPESA em aberto (aberto ou parcialmente pago) com
 * vencimento ≤ hoje + N dias (N = `days`, padrão 7) — os vencidos entram e são contados à parte.
 * Valor = líquido − já pago. Domínio financeiro: `financial-entries.view.any`.
 */
final class PayablesDueWidget extends BaseWidgetProvider
{
    private const LIST_LIMIT = 10;

    public function key(): string
    {
        return 'payables_due';
    }

    public function label(): string
    {
        return 'Contas a vencer';
    }

    public function description(): string
    {
        return 'Contas a pagar em aberto que vencem nos próximos dias (e as vencidas).';
    }

    public function domain(): string
    {
        return 'finance';
    }

    public function permission(): string
    {
        return 'financial-entries.view.any';
    }

    public function parameters(): array
    {
        return ['days' => ['type' => 'int', 'default' => 7, 'min' => 1, 'max' => 90]];
    }

    public function data(DashboardWidgetContext $context): array
    {
        $entries = $this->scoped(FinancialEntry::query(), $context)
            ->where('nature', FinancialNature::EXPENSE->value)
            ->whereIn('status', [FinancialEntryStatus::OPEN->value, FinancialEntryStatus::PARTIALLY_PAID->value])
            ->whereDate('due_date', '<=', $context->today->addDays($context->int('days'))->toDateString())
            ->orderBy('due_date')->orderBy('id')
            ->get(['id', 'description', 'due_date', 'net_amount', 'paid_amount']);
        $open = fn (FinancialEntry $entry): Money => Money::of($entry->net_amount)->minus($entry->paid_amount ?? '0');
        $overdue = $entries->filter(fn (FinancialEntry $entry): bool => $entry->due_date->lt($context->today));

        return [
            'days' => $context->int('days'),
            'count' => $entries->count(),
            'total' => Money::sum($entries->map($open))->toString(),
            'overdue_count' => $overdue->count(),
            'overdue_total' => Money::sum($overdue->map($open))->toString(),
            'items' => $entries->take(self::LIST_LIMIT)->map(fn (FinancialEntry $entry): array => [
                'id' => $entry->id, 'description' => $entry->description, 'due_date' => $entry->due_date->toDateString(),
                'amount' => $open($entry)->toString(), 'overdue' => $entry->due_date->lt($context->today),
            ])->values()->all(),
        ];
    }
}
