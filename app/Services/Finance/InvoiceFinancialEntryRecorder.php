<?php

namespace App\Services\Finance;

use App\Enums\FinancialEntryStatus;
use App\Enums\FinancialNature;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;

/**
 * Fatura de atendimento paga → `financial_entries` de receita — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 *
 * Chamado do único ponto de escrita "fatura foi paga" (`PaymentService::markInvoiceAsPaid()`),
 * que cobre TANTO o webhook do gateway QUANTO o `mark-as-paid` manual do balcão — por isso
 * opera por OWNERSHIP direta da fatura, nunca por um `User` corrente que o webhook não tem.
 */
final class InvoiceFinancialEntryRecorder
{
    private const CATEGORY_NAME = 'Receita de serviços';

    public function __construct(private readonly FinancialCategoryProvisioner $categories) {}

    public function recordForPaidInvoice(Invoice $invoice, Payment $payment): void
    {
        if (FinancialEntry::where('reference_type', 'invoice')->where('reference_id', $invoice->id)->exists()) {
            return;
        }

        $ownership = ['organization_id' => $invoice->organization_id, 'professional_id' => $invoice->professional_id];
        $category = $this->categories->systemCategoryFor($ownership, FinancialNature::REVENUE, self::CATEGORY_NAME);
        $amount = round((float) $payment->amount, 2);
        $accrualDate = ($invoice->issue_date ?? $invoice->created_at)->toDateString();

        FinancialEntry::create([
            'financial_category_id' => $category->id,
            'description' => 'Fatura '.($invoice->invoice_number ?? $invoice->id),
            'nature' => FinancialNature::REVENUE,
            'due_date' => $accrualDate,
            'accrual_date' => $accrualDate,
            'amount' => $amount,
            'net_amount' => $amount,
            'paid_at' => $payment->paid_at ?? now(),
            'paid_amount' => $amount,
            'status' => FinancialEntryStatus::PAID,
            'reference_type' => 'invoice',
            'reference_id' => $invoice->id,
            'created_by' => $invoice->professional_id,
        ] + $ownership);
    }
}
