<?php

namespace App\Services\Finance;

use App\Enums\FinancialEntryStatus;
use App\Enums\FinancialNature;
use App\Models\FinancialEntry;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Compra recebida → `financial_entries` de despesa, 1:1 com `purchase_installments` — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 *
 * `purchase_installments` continua existindo (é FK de `Purchase`, exibida no resource da
 * compra); este recorder só cria a PERNA CONTÁBIL ao lado, ligada por
 * `purchase_installments.financial_entry_id`. A migration de backfill
 * (`2026_10_20_300003_...`) cobre as compras recebidas antes desta entrega.
 */
final class PurchaseFinancialEntryRecorder
{
    public function __construct(private readonly FinancialCategoryProvisioner $categories) {}

    public function recordForReceivedPurchase(Purchase $purchase, User $user): void
    {
        $installments = $purchase->installments()->whereNull('financial_entry_id')->orderBy('number')->get();

        if ($installments->isEmpty()) {
            return;
        }

        $category = $this->categories->systemCategory($user, FinancialNature::EXPENSE, 'Fornecedores', 'Custos e despesas');
        $seriesId = $installments->count() > 1 ? (string) Str::uuid() : null;
        $total = $installments->count();

        foreach ($installments as $installment) {
            $entry = FinancialEntry::create([
                'organization_id' => $purchase->organization_id,
                'professional_id' => $purchase->professional_id,
                'financial_category_id' => $category->id,
                'account_id' => $installment->financial_account_id,
                'supplier_id' => $purchase->supplier_id,
                'payment_method_id' => $installment->payment_method_id,
                'description' => sprintf('Compra %s — parcela %d/%d', $purchase->code, $installment->number, $total),
                'nature' => FinancialNature::EXPENSE,
                'due_date' => $installment->due_date,
                'accrual_date' => $purchase->entered_at,
                'amount' => $installment->amount,
                'net_amount' => $installment->amount,
                'status' => FinancialEntryStatus::OPEN,
                'series_id' => $seriesId,
                'installment_number' => $seriesId === null ? null : $installment->number,
                'installment_total' => $seriesId === null ? null : $total,
                'reference_type' => 'purchase_installment',
                'reference_id' => $installment->id,
                'created_by' => $user->id,
            ]);

            $installment->update(['financial_entry_id' => $entry->id]);
        }
    }

    /** Espelha o cancelamento: parcela cancelada leva o lançamento junto (a paga não se mexe). */
    public function cancelForPurchase(Purchase $purchase): void
    {
        $entryIds = $purchase->installments()->whereNotNull('financial_entry_id')->pluck('financial_entry_id');

        FinancialEntry::whereIn('id', $entryIds)
            ->where('status', '!=', FinancialEntryStatus::PAID->value)
            ->update(['status' => FinancialEntryStatus::CANCELLED->value]);
    }
}
