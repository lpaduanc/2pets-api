<?php

namespace App\Services\Commercial;

use App\Enums\AcquirerSettlementStatus;
use App\Enums\ReceivableStatus;
use App\Models\AcquirerSettlement;
use App\Models\Receivable;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Conciliação clínica × adquirente — contrato docs/gap-simplesvet/04.
 *
 * ⚠️ Não confundir com o repasse PLATAFORMA × PROFISSIONAL (doc 09, `payouts`/`commissions`).
 * A observação de negócio do próprio documento é explícita: são dois eixos, e misturá-los na
 * mesma tabela transformaria receita da plataforma em despesa da clínica.
 */
final class AcquirerReconciliationService
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    /**
     * O que a clínica ESPERA receber das adquirentes no período: as PARCELAS pendentes da agenda
     * de recebíveis (docs/gap-petflow 1.1). É a coluna contra a qual o extrato real da operadora
     * é conferido.
     *
     * Agrupado por (data prevista × forma de pagamento) porque é assim que a adquirente
     * deposita: um depósito por dia por bandeira/modalidade, não um por venda — e um
     * recebimento em 3x cai em 3 depósitos.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function expectedSettlements(User $user, \DateTimeInterface $from, \DateTimeInterface $to): Collection
    {
        return $this->scope->scopeQuery(Receivable::query(), $user)
            ->pending()
            ->whereBetween('expected_date', [
                Carbon::instance($from)->toDateString(),
                Carbon::instance($to)->toDateString(),
            ])
            ->with('paymentMethod')
            ->orderBy('expected_date')
            ->get()
            ->groupBy(fn (Receivable $receivable): string => $receivable->expected_date->toDateString().'|'.$receivable->payment_method_id)
            ->map(fn (Collection $group): array => $this->expectedGroup($group))
            ->values();
    }

    /**
     * Concilia um depósito com as parcelas informadas (`receivable_ids`) — ou, no contrato
     * antigo, com recebimentos inteiros (`receipt_ids`), que viram as parcelas pendentes deles.
     *
     * Se o líquido do depósito não fechar com a soma (fora da tolerância de centavos), o depósito
     * é marcado `divergent` em vez de `reconciled` — e os itens ficam gravados do mesmo jeito,
     * para quem concilia ver o que tentou casar. Só depósito conciliado dá a parcela por recebida.
     *
     * @param  list<int>  $receivableIds
     * @param  list<int>  $receiptIds
     */
    public function match(AcquirerSettlement $settlement, array $receivableIds, User $user, array $receiptIds = []): AcquirerSettlement
    {
        abort_if(
            $settlement->status === AcquirerSettlementStatus::RECONCILED,
            422,
            'Este depósito já foi conciliado.'
        );

        return DB::transaction(function () use ($settlement, $receivableIds, $receiptIds, $user): AcquirerSettlement {
            $lines = $this->linesToMatch($user, $receivableIds, $receiptIds);
            abort_if($lines->isEmpty(), 422, 'Nenhuma parcela válida foi informada.');

            $this->releasePreviousMatch($settlement);
            $linkedNet = $this->storeItems($settlement, $lines);
            $closes = $linkedNet->minus($settlement->net_amount)->abs()->isLessThanOrEqualTo((string) AcquirerSettlement::RECONCILIATION_TOLERANCE);

            if ($closes) {
                $this->settleReceivables($settlement, $lines);
            }

            $settlement->update([
                'status' => $closes ? AcquirerSettlementStatus::RECONCILED : AcquirerSettlementStatus::DIVERGENT,
                'reconciled_at' => now(),
                'reconciled_by' => $user->id,
                'divergence_note' => $closes ? null : sprintf(
                    'Depósito de R$ %s não fecha com R$ %s das %d parcela(s) vinculadas (diferença de R$ %s).',
                    number_format((float) $settlement->net_amount, 2, ',', '.'),
                    number_format($linkedNet->toFloat(), 2, ',', '.'),
                    $lines->count(),
                    number_format($linkedNet->minus($settlement->net_amount)->abs()->toFloat(), 2, ',', '.'),
                ),
            ]);

            return $settlement->fresh(['items.receipt', 'paymentMethod', 'destinationAccount']);
        });
    }

    /**
     * @param  Collection<int, Receivable>  $group
     * @return array<string, mixed>
     */
    private function expectedGroup(Collection $group): array
    {
        $first = $group->first();

        return [
            'expected_date' => $first->expected_date->toDateString(),
            'payment_method_id' => $first->payment_method_id,
            'payment_method' => $first->paymentMethod?->name,
            'acquirer' => $first->paymentMethod?->acquirer,
            'receivable_ids' => $group->pluck('id')->all(),
            'receipt_ids' => $group->pluck('sale_receipt_id')->unique()->values()->all(),
            'receipt_count' => $group->count(),
            'gross_amount' => Money::sum($group->pluck('gross_amount'))->toFloat(),
            'fee_amount' => Money::sum($group->pluck('fee_amount'))->toFloat(),
            'net_amount' => Money::sum($group->pluck('net_amount'))->toFloat(),
        ];
    }

    /**
     * Parcelas do escopo do usuário: as informadas, ou as pendentes dos recebimentos informados.
     *
     * @param  list<int>  $receivableIds
     * @param  list<int>  $receiptIds
     * @return Collection<int, Receivable>
     */
    private function linesToMatch(User $user, array $receivableIds, array $receiptIds): Collection
    {
        // Só parcela PENDENTE: a já liquidada por outro depósito não pode ser paga duas vezes (a do
        // próprio depósito voltou a pendente em `releasePreviousMatch`). As duas chaves podem vir
        // juntas (tela com grupos novos e antigos): vale a união.
        $pending = fn () => $this->scope->scopeQuery(Receivable::query(), $user)->pending();
        $chosen = $receivableIds === [] ? collect() : $pending()->whereIn('id', $receivableIds)->get();

        return $chosen->concat($this->earliestPendingOf($pending, $receiptIds))->unique('id')->values();
    }

    /**
     * Contrato antigo (`receipt_ids`): um depósito paga UMA parcela de cada recebimento — a
     * primeira pendente —, não o recebimento inteiro (que cairia em três depósitos se fosse 3x).
     *
     * @param  \Closure(): \Illuminate\Database\Eloquent\Builder<Receivable>  $pending
     * @param  list<int>  $receiptIds
     * @return Collection<int, Receivable>
     */
    private function earliestPendingOf(\Closure $pending, array $receiptIds): Collection
    {
        if ($receiptIds === []) {
            return collect();
        }

        return $pending()->whereIn('sale_receipt_id', $receiptIds)->orderBy('installment_number')->get()
            ->unique('sale_receipt_id')->values();
    }

    /** Reconciliar de novo substitui a seleção anterior: as parcelas dela voltam a pendentes. */
    private function releasePreviousMatch(AcquirerSettlement $settlement): void
    {
        Receivable::query()->where('acquirer_settlement_id', $settlement->id)->update([
            'status' => ReceivableStatus::PENDING->value,
            'settled_at' => null,
            'acquirer_settlement_id' => null,
        ]);

        $settlement->items()->delete();
    }

    /** @param  Collection<int, Receivable>  $lines */
    private function storeItems(AcquirerSettlement $settlement, Collection $lines): Money
    {
        foreach ($lines as $receivable) {
            $settlement->items()->create([
                'sale_receipt_id' => $receivable->sale_receipt_id,
                'receivable_id' => $receivable->id,
                'amount' => $receivable->net_amount,
            ]);
        }

        return Money::sum($lines->pluck('net_amount'));
    }

    /** @param  Collection<int, Receivable>  $lines */
    private function settleReceivables(AcquirerSettlement $settlement, Collection $lines): void
    {
        Receivable::query()->whereIn('id', $lines->pluck('id'))->update([
            'status' => ReceivableStatus::SETTLED->value,
            'settled_at' => $settlement->deposit_date,
            'acquirer_settlement_id' => $settlement->id,
        ]);
    }

    /** Marcação manual de divergência, para o depósito que a clínica não consegue casar. */
    public function markDivergent(AcquirerSettlement $settlement, User $user, string $note): AcquirerSettlement
    {
        $settlement->update([
            'status' => AcquirerSettlementStatus::DIVERGENT,
            'divergence_note' => $note,
            'reconciled_at' => now(),
            'reconciled_by' => $user->id,
        ]);

        return $settlement->fresh(['paymentMethod', 'destinationAccount']);
    }

    /**
     * Os três totalizadores do topo da tela (`Todos`, `Conciliados`, `Não conciliados`).
     *
     * @return array<string, int>
     */
    public function counters(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $base = fn () => $this->scope->scopeQuery(AcquirerSettlement::query(), $user)
            ->whereBetween('deposit_date', [
                Carbon::instance($from)->toDateString(),
                Carbon::instance($to)->toDateString(),
            ]);

        return [
            'all' => $base()->count(),
            'reconciled' => $base()->where('status', AcquirerSettlementStatus::RECONCILED->value)->count(),
            'pending' => $base()->where('status', AcquirerSettlementStatus::PENDING->value)->count(),
            'divergent' => $base()->where('status', AcquirerSettlementStatus::DIVERGENT->value)->count(),
        ];
    }
}
