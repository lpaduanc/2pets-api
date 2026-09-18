<?php

namespace App\Services\Commercial;

use App\Enums\AcquirerSettlementStatus;
use App\Models\AcquirerSettlement;
use App\Models\SaleReceipt;
use App\Models\User;
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
     * O que a clínica ESPERA receber das adquirentes no período, a partir dos recebimentos já
     * feitos e do `settlement_days` de cada forma. É a coluna contra a qual o extrato real da
     * operadora é conferido.
     *
     * Agrupado por (data prevista × forma de pagamento) porque é assim que a adquirente
     * deposita: um depósito por dia por bandeira/modalidade, não um por venda.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function expectedSettlements(User $user, \DateTimeInterface $from, \DateTimeInterface $to): Collection
    {
        return SaleReceipt::query()
            ->awaitingSettlement()
            ->whereBetween('expected_settlement_date', [
                Carbon::instance($from)->toDateString(),
                Carbon::instance($to)->toDateString(),
            ])
            ->whereHas('sale', fn ($query) => $this->scope->scopeQuery($query, $user))
            ->with('paymentMethod')
            ->get()
            ->groupBy(fn (SaleReceipt $receipt): string => $receipt->expected_settlement_date->toDateString()
                .'|'.$receipt->payment_method_id)
            ->map(function (Collection $group): array {
                $first = $group->first();

                return [
                    'expected_date' => $first->expected_settlement_date->toDateString(),
                    'payment_method_id' => $first->payment_method_id,
                    'payment_method' => $first->paymentMethod?->name,
                    'acquirer' => $first->paymentMethod?->acquirer,
                    'receipt_ids' => $group->pluck('id')->all(),
                    'receipt_count' => $group->count(),
                    'gross_amount' => round((float) $group->sum('amount'), 2),
                    'fee_amount' => round((float) $group->sum('operator_fee'), 2),
                    'net_amount' => round((float) $group->sum('net_amount'), 2),
                ];
            })
            ->values();
    }

    /**
     * Concilia um depósito com os recebimentos informados.
     *
     * Se o líquido do depósito não fechar com a soma dos recebimentos (fora da tolerância de
     * centavos), o depósito é marcado `divergent` em vez de `reconciled` — e os itens ficam
     * gravados do mesmo jeito. Critério de aceite do doc 04: "Depósito cujo valor líquido não
     * fecha com a soma dos recebimentos vinculados é marcado `divergent`".
     *
     * Marcar divergente COM os itens (e não recusar a operação) é deliberado: quem concilia
     * precisa ver quais recebimentos ele tentou casar para descobrir o que faltou.
     *
     * @param  list<int>  $receiptIds
     */
    public function match(AcquirerSettlement $settlement, array $receiptIds, User $user): AcquirerSettlement
    {
        abort_if(
            $settlement->status === AcquirerSettlementStatus::RECONCILED,
            422,
            'Este depósito já foi conciliado.'
        );

        return DB::transaction(function () use ($settlement, $receiptIds, $user): AcquirerSettlement {
            $receipts = SaleReceipt::query()
                ->whereIn('id', $receiptIds)
                ->whereHas('sale', fn ($query) => $this->scope->scopeQuery($query, $user))
                ->get();

            abort_if($receipts->isEmpty(), 422, 'Nenhum recebimento válido foi informado.');

            // Recria os itens do zero: reconciliar de novo com uma seleção diferente tem que
            // substituir a anterior, não somar em cima dela.
            $settlement->items()->delete();

            foreach ($receipts as $receipt) {
                $settlement->items()->create([
                    'sale_receipt_id' => $receipt->id,
                    'amount' => $receipt->net_amount,
                ]);
            }

            $linkedNet = round((float) $receipts->sum('net_amount'), 2);
            $closes = abs($linkedNet - (float) $settlement->net_amount) <= AcquirerSettlement::RECONCILIATION_TOLERANCE;

            $settlement->update([
                'status' => $closes ? AcquirerSettlementStatus::RECONCILED : AcquirerSettlementStatus::DIVERGENT,
                'reconciled_at' => now(),
                'reconciled_by' => $user->id,
                'divergence_note' => $closes ? null : sprintf(
                    'Depósito de R$ %s não fecha com R$ %s dos %d recebimento(s) vinculados (diferença de R$ %s).',
                    number_format((float) $settlement->net_amount, 2, ',', '.'),
                    number_format($linkedNet, 2, ',', '.'),
                    $receipts->count(),
                    number_format(abs($linkedNet - (float) $settlement->net_amount), 2, ',', '.'),
                ),
            ]);

            return $settlement->fresh(['items.receipt', 'paymentMethod', 'destinationAccount']);
        });
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
