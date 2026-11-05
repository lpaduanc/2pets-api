<?php

namespace App\Services\Finance\Anticipation;

use App\Enums\FinancialNature;
use App\Models\FinancialEntry;
use App\Models\ReceivableAnticipation;
use App\Models\User;
use App\Services\Finance\FinancialCategoryProvisioner;
use App\Services\Finance\FinancialEntryService;
use App\Support\Money\Money;

/**
 * A taxa da antecipação no financeiro (PLANO 2.1): despesa PAGA "Antecipação de recebíveis" na
 * conta de destino, na data da antecipação, numa categoria do sistema com `dre_treatment =
 * financial` — a DRE gerencial (1.7) mostra no resultado financeiro. Pelo `FinancialEntryService`
 * (lança e dá baixa), com referência `receivable_anticipation`.
 *
 * O líquido NÃO vira receita nova: o bruto do cartão já entrou como receita (e no saldo da conta)
 * no dia da venda (decisão de 2026-10-29, plano 1.1); a antecipação só antecipa o depósito, e o
 * único fato contábil novo é a taxa.
 */
final class AnticipationLedger
{
    public const CATEGORY = 'Antecipação de recebíveis';

    public const REFERENCE_TYPE = 'receivable_anticipation';

    public function __construct(
        private readonly FinancialCategoryProvisioner $categories,
        private readonly FinancialEntryService $entries,
    ) {}

    public function recordFee(User $user, ReceivableAnticipation $anticipation): void
    {
        $fee = Money::of($anticipation->fee_total);

        if (! $fee->isPositive()) {
            return;
        }

        $category = $this->categories->systemCategory($user, FinancialNature::EXPENSE, self::CATEGORY);
        $date = $anticipation->anticipated_at->toDateString();
        $entry = $this->entries->create($user, [
            'financial_category_id' => $category->id,
            'account_id' => $anticipation->account_id,
            'description' => "Antecipação de recebíveis #{$anticipation->id}",
            'nature' => FinancialNature::EXPENSE->value,
            'due_date' => $date,
            'accrual_date' => $date,
            'amount' => $fee->toString(),
        ])->first();
        $entry->forceFill(['reference_type' => self::REFERENCE_TYPE, 'reference_id' => $anticipation->id])->save();
        $this->entries->settle($entry, $user, ['paid_amount' => $fee->toString(), 'paid_at' => $date, 'account_id' => $anticipation->account_id]);
        $anticipation->update(['financial_entry_id' => $entry->id]);
    }

    /** Cancelamento: estorna a baixa e cancela a despesa (o lançamento fica no histórico). */
    public function reverseFee(ReceivableAnticipation $anticipation): void
    {
        $entry = $anticipation->financial_entry_id === null ? null : FinancialEntry::find($anticipation->financial_entry_id);

        if ($entry === null) {
            return;
        }

        $this->entries->cancel($this->entries->unsettle($entry));
    }
}
