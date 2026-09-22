<?php

use App\Enums\FinancialCategoryKind;
use App\Enums\FinancialEntryStatus;
use App\Enums\FinancialNature;
use App\Enums\PurchaseInstallmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md — dívida explícita deixada
 * pelo doc 06 (comentário original de `create_purchases_tables`): "quando `financial_entries`
 * entrar, estas linhas são a origem dela (uma migração 1:1)". Esta migration:
 *
 *  1. adiciona `purchase_installments.financial_entry_id` (FK nullable);
 *  2. faz o BACKFILL — cria 1 `financial_entries` de despesa para cada parcela de compra JÁ
 *     RECEBIDA antes desta entrega, para o DRE histórico não começar zerado (critério de
 *     aceite do doc 02). Compra em rascunho/cancelada não entra: nunca gerou dívida real.
 *
 * A partir desta entrega, `PurchaseFinancialEntryRecorder` cuida das compras novas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_installments', function (Blueprint $table): void {
            $table->foreignId('financial_entry_id')->nullable()->after('financial_account_id')
                ->constrained('financial_entries')->nullOnDelete();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('purchase_installments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('financial_entry_id');
        });
    }

    private function backfill(): void
    {
        $installments = DB::table('purchase_installments as pi')
            ->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
            ->whereNull('pi.financial_entry_id')
            ->where('p.status', 'received')
            ->orderBy('pi.purchase_id')
            ->orderBy('pi.number')
            ->select('pi.*', 'p.organization_id as p_organization_id', 'p.professional_id as p_professional_id',
                'p.code as p_code', 'p.supplier_id as p_supplier_id', 'p.entered_at as p_entered_at',
                'p.created_by as p_created_by')
            ->get();

        if ($installments->isEmpty()) {
            return;
        }

        $categoryCache = [];
        $seriesCache = [];
        $totalsByPurchase = $installments->countBy('purchase_id');

        foreach ($installments as $installment) {
            $categoryId = $categoryCache[$this->ownershipKey($installment)] ??= $this->categoryIdFor($installment);
            $seriesId = $totalsByPurchase[$installment->purchase_id] > 1
                ? ($seriesCache[$installment->purchase_id] ??= (string) Str::uuid())
                : null;

            $entryId = DB::table('financial_entries')->insertGetId([
                'organization_id' => $installment->p_organization_id,
                'professional_id' => $installment->p_professional_id,
                'financial_category_id' => $categoryId,
                'account_id' => $installment->financial_account_id,
                'supplier_id' => $installment->p_supplier_id,
                'payment_method_id' => $installment->payment_method_id,
                'description' => sprintf('Compra %s — parcela %d/%d', $installment->p_code, $installment->number, $totalsByPurchase[$installment->purchase_id]),
                'nature' => FinancialNature::EXPENSE->value,
                'due_date' => $installment->due_date,
                'accrual_date' => $installment->p_entered_at,
                'amount' => $installment->amount,
                'discount' => 0, 'fine' => 0, 'interest' => 0,
                'net_amount' => $installment->amount,
                'paid_at' => $installment->paid_at,
                'paid_amount' => $installment->status === PurchaseInstallmentStatus::PAID->value ? $installment->amount : null,
                'status' => $this->statusFor($installment),
                'series_id' => $seriesId,
                'installment_number' => $seriesId === null ? null : $installment->number,
                'installment_total' => $seriesId === null ? null : $totalsByPurchase[$installment->purchase_id],
                'reference_type' => 'purchase_installment',
                'reference_id' => $installment->id,
                'created_by' => $installment->p_created_by,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('purchase_installments')->where('id', $installment->id)->update(['financial_entry_id' => $entryId]);
        }
    }

    private function statusFor(object $installment): string
    {
        return match ($installment->status) {
            PurchaseInstallmentStatus::PAID->value => FinancialEntryStatus::PAID->value,
            PurchaseInstallmentStatus::CANCELLED->value => FinancialEntryStatus::CANCELLED->value,
            default => FinancialEntryStatus::OPEN->value,
        };
    }

    private function ownershipKey(object $installment): string
    {
        return ($installment->p_organization_id ?? 'null').'|'.($installment->p_professional_id ?? 'null');
    }

    private function categoryIdFor(object $installment): int
    {
        $organizationId = $installment->p_organization_id;
        $professionalId = $installment->p_professional_id;

        $existing = DB::table('financial_categories')
            ->where('name', 'Fornecedores')
            ->where('nature', FinancialNature::EXPENSE->value)
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->when($organizationId === null, fn ($q) => $q->whereNull('organization_id')->where('professional_id', $professionalId))
            ->value('id');

        if ($existing !== null) {
            return $existing;
        }

        return DB::table('financial_categories')->insertGetId([
            'organization_id' => $organizationId,
            'professional_id' => $professionalId,
            'name' => 'Fornecedores',
            'nature' => FinancialNature::EXPENSE->value,
            'kind' => FinancialCategoryKind::ENTRY->value,
            'is_system' => true,
            'sort_order' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
