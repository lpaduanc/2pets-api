<?php

use App\Enums\ReceivableStatus;
use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Taxa de cartão por bandeira e parcela + agenda de recebíveis — docs/gap-petflow/PLANO.md, 1.1.
 *
 * - `payment_method_fee_tiers`: a taxa e o prazo dependem de forma × bandeira × parcelas.
 *   Sem faixa, vale o `fee_percent`/`fee_fixed`/`settlement_days` da própria forma.
 * - `sale_receipts` guarda a bandeira, a autorização e o snapshot da taxa que valeu na venda.
 * - `receivables`: uma linha por parcela que a adquirente vai depositar (bruto, taxa, líquido,
 *   data prevista). A soma das parcelas é o recebimento, centavo a centavo.
 * - `acquirer_settlement_items.receivable_id`: a conciliação casa o depósito por PARCELA — um
 *   recebimento em 3x cai em 3 depósitos diferentes.
 *
 * Por decisão do dono do produto (2026-10-29), o saldo da conta continua recebendo o bruto do
 * cartão no dia da venda; a agenda é informativa e serve à conciliação, não ao fluxo de caixa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_method_fee_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_method_id')->constrained()->cascadeOnDelete();
            // `null` = qualquer bandeira (faixa de reserva quando a bandeira não tem faixa própria).
            $table->string('card_brand', 20)->nullable();
            $table->unsignedSmallInteger('installments_from');
            $table->unsignedSmallInteger('installments_to');
            $table->decimal('fee_percent', 8, 4)->default(0);
            $table->decimal('fee_fixed', 10, 2)->default(0);
            $table->unsignedSmallInteger('first_settlement_days')->default(30);
            $table->unsignedSmallInteger('interval_days')->default(30);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index(['payment_method_id', 'card_brand']);
        });

        Schema::table('sale_receipts', function (Blueprint $table): void {
            $table->string('card_brand', 20)->nullable()->after('installments');
            $table->string('authorization_code', 40)->nullable()->after('card_brand');
            $table->string('terminal_label', 60)->nullable()->after('authorization_code');
            $table->foreignId('fee_tier_id')->nullable()->after('terminal_label')
                ->constrained('payment_method_fee_tiers')->nullOnDelete();
            $table->decimal('fee_percent_applied', 8, 4)->nullable()->after('fee_tier_id');
            $table->decimal('fee_fixed_applied', 10, 2)->nullable()->after('fee_percent_applied');
        });

        Schema::create('receivables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sale_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained();
            $table->string('card_brand', 20)->nullable();
            $table->unsignedSmallInteger('installment_number');
            $table->unsignedSmallInteger('installments_count');
            $table->decimal('gross_amount', 14, 2);
            $table->decimal('fee_amount', 14, 2);
            $table->decimal('net_amount', 14, 2);
            $table->date('expected_date');
            $table->string('status', 20)->default(ReceivableStatus::PENDING->value);
            // Antecipação (plano 2.1) ainda não existe: coluna solta, sem FK, como o
            // `acquirer_settlement_items.sale_receipt_id` nasceu.
            $table->unsignedBigInteger('anticipation_id')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('acquirer_settlement_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['sale_receipt_id', 'installment_number']);
            $table->index(['organization_id', 'expected_date']);
            $table->index(['professional_id', 'expected_date']);
            $table->index(['status', 'expected_date']);
        });

        Schema::table('acquirer_settlement_items', function (Blueprint $table): void {
            $table->foreignId('receivable_id')->nullable()->after('sale_receipt_id')->constrained()->nullOnDelete();
            $table->dropUnique('settlement_receipt_unique');
            $table->unique(['acquirer_settlement_id', 'receivable_id'], 'settlement_receivable_unique');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                "ALTER TABLE receivables ADD CONSTRAINT receivables_status_check CHECK (status IN ('%s'))",
                implode("','", ReceivableStatus::values())
            ));
            DB::statement('ALTER TABLE payment_method_fee_tiers ADD CONSTRAINT fee_tiers_installments_check CHECK (installments_from >= 1 AND installments_from <= installments_to)');
        }

        $this->backfillReceivables();
    }

    public function down(): void
    {
        // Duas parcelas do mesmo recebimento no mesmo depósito (intervalo 0) violariam o unique
        // antigo por recebimento: fica um item por (depósito, recebimento), somando o valor.
        DB::statement('UPDATE acquirer_settlement_items i SET amount = s.total FROM (
            SELECT MIN(id) AS keep, SUM(amount) AS total FROM acquirer_settlement_items
            GROUP BY acquirer_settlement_id, sale_receipt_id) s WHERE i.id = s.keep');
        DB::statement('DELETE FROM acquirer_settlement_items WHERE id NOT IN (
            SELECT MIN(id) FROM acquirer_settlement_items GROUP BY acquirer_settlement_id, sale_receipt_id)');

        Schema::table('acquirer_settlement_items', function (Blueprint $table): void {
            $table->dropUnique('settlement_receivable_unique');
            $table->dropConstrainedForeignId('receivable_id');
            $table->unique(['acquirer_settlement_id', 'sale_receipt_id'], 'settlement_receipt_unique');
        });

        Schema::dropIfExists('receivables');

        Schema::table('sale_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fee_tier_id');
            $table->dropColumn(['card_brand', 'authorization_code', 'terminal_label', 'fee_percent_applied', 'fee_fixed_applied']);
        });

        Schema::dropIfExists('payment_method_fee_tiers');
    }

    /**
     * Recebimentos de cartão que já existiam ganham suas parcelas com a taxa QUE FOI GRAVADA
     * neles (`operator_fee`), não a vigente: o histórico não muda. Mesma regra do serviço —
     * diferença de centavo do bruto na 1ª parcela, taxa rateada pelo maior resto. Data prevista
     * a partir da `expected_settlement_date` gravada, uma parcela a cada 30 dias. Recebimento já
     * conciliado entra como liquidado.
     */
    private function backfillReceivables(): void
    {
        DB::table('sale_receipts')
            ->join('payment_methods', 'payment_methods.id', '=', 'sale_receipts.payment_method_id')
            ->join('sales', 'sales.id', '=', 'sale_receipts.sale_id')
            ->whereIn('payment_methods.kind', ['credit_card', 'debit_card', 'recurring_link'])
            ->whereNotNull('sale_receipts.expected_settlement_date')
            ->orderBy('sale_receipts.id')
            ->select('sale_receipts.*', 'sales.organization_id', 'sales.professional_id', 'sales.status as sale_status')
            ->chunk(500, function ($receipts): void {
                foreach ($receipts as $receipt) {
                    DB::table('receivables')->insert($this->backfillRows($receipt));
                }
            });
    }

    /** @return list<array<string, mixed>> */
    private function backfillRows(object $receipt): array
    {
        $count = max(1, (int) $receipt->installments);
        $gross = Money::of($receipt->amount)->splitInInstallments($count);
        $fees = Money::of($receipt->operator_fee)->allocate($gross);
        // Recebida só se o item é de um depósito CONCILIADO — o código antigo gravava itens também
        // em depósito divergente, e esses continuam a receber.
        $settlementId = DB::table('acquirer_settlement_items')
            ->join('acquirer_settlements', 'acquirer_settlements.id', '=', 'acquirer_settlement_items.acquirer_settlement_id')
            ->where('acquirer_settlement_items.sale_receipt_id', $receipt->id)
            ->where('acquirer_settlements.status', 'reconciled')
            ->value('acquirer_settlements.id');
        $status = match (true) {
            $receipt->sale_status === 'cancelled' => ReceivableStatus::CANCELLED,
            $settlementId !== null => ReceivableStatus::SETTLED,
            default => ReceivableStatus::PENDING,
        };
        $first = Carbon::parse($receipt->expected_settlement_date);
        $rows = [];

        foreach ($gross as $index => $amount) {
            $rows[] = [
                'organization_id' => $receipt->organization_id,
                'professional_id' => $receipt->professional_id,
                'sale_receipt_id' => $receipt->id,
                'payment_method_id' => $receipt->payment_method_id,
                'installment_number' => $index + 1,
                'installments_count' => $count,
                'gross_amount' => $amount->toString(),
                'fee_amount' => $fees[$index]->toString(),
                'net_amount' => $amount->minus($fees[$index])->toString(),
                'expected_date' => $first->copy()->addDays(30 * $index)->toDateString(),
                'status' => $status->value,
                'acquirer_settlement_id' => $status === ReceivableStatus::SETTLED ? $settlementId : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }
};
