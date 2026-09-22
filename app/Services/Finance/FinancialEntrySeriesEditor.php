<?php

namespace App\Services\Finance;

use App\Enums\FinancialEntryStatus;
use App\Models\FinancialEntry;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Editar a parcela N com replicar para as próximas" — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md. Altera a parcela informada em
 * diante, nunca as anteriores. Parcela já baixada não entra no lote: quem quer mudar uma
 * parcela paga usa `FinancialEntryService::unsettle()` nela primeiro, individualmente.
 */
final class FinancialEntrySeriesEditor
{
    private const RELATIONS = ['category', 'account', 'supplier', 'paymentMethod'];

    public function __construct(private readonly CommercialScopeResolver $scope) {}

    /**
     * @param  array<string, mixed>  $changes
     * @return Collection<int, FinancialEntry>
     */
    public function updateFrom(string $seriesId, int $fromInstallment, User $user, array $changes): Collection
    {
        $entries = $this->seriesFrom($seriesId, $fromInstallment, $user);
        $this->assertEditable($entries);

        $allowed = collect($changes)->only([
            'description', 'due_date', 'payment_method_id', 'account_id', 'supplier_id', 'notes', 'amount',
        ])->all();

        DB::transaction(function () use ($entries, $allowed): void {
            foreach ($entries as $entry) {
                $amount = $allowed['amount'] ?? (float) $entry->amount;
                $netAmount = round((float) $amount - (float) $entry->discount + (float) $entry->fine + (float) $entry->interest, 2);
                $entry->update($allowed + ['net_amount' => $netAmount]);
            }
        });

        return FinancialEntry::whereIn('id', $entries->pluck('id'))->with(self::RELATIONS)->orderBy('installment_number')->get();
    }

    /**
     * @return Collection<int, FinancialEntry>
     */
    private function seriesFrom(string $seriesId, int $fromInstallment, User $user): Collection
    {
        $entries = $this->scope->scopeQuery(FinancialEntry::query(), $user)
            ->where('series_id', $seriesId)
            ->where('installment_number', '>=', $fromInstallment)
            ->orderBy('installment_number')
            ->get();

        abort_if($entries->isEmpty(), 404, 'Série de parcelas não encontrada.');

        return $entries;
    }

    /** @param  Collection<int, FinancialEntry>  $entries */
    private function assertEditable(Collection $entries): void
    {
        foreach ($entries as $entry) {
            abort_if(
                $entry->status !== FinancialEntryStatus::OPEN,
                422,
                sprintf('A parcela %d já foi baixada e não pode ser alterada em lote.', $entry->installment_number)
            );
        }
    }
}
