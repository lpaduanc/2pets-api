<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crediário parcelado (docs/gap-petflow/PLANO.md, 2.2), sobre a conta corrente que já existe.
 *
 * - `organizations.credit_*`: política de encargos da clínica — multa (≤ 2%, CDC art. 52 §1º),
 *   juros de mora ao mês (≤ 1%, CC art. 406 c/c CTN art. 161 §1º) e carência (dias). Padrões: 2%,
 *   1% a.m., 0 dias.
 * - `client_credit_plans`: o fiado de uma venda parcelado, com SNAPSHOT da política no dia — mudar
 *   a configuração depois não muda o que já foi combinado.
 * - `client_credit_installments`: parcelas; `paid_principal`/`paid_fees` acumulados, e o estado dos
 *   encargos (`fees_settled_on`: último acerto completo; `open_fees_paid`: encargo pago em parte
 *   desde então). Os encargos em si nunca são gravados por job — calculados na leitura.
 * - `client_credit_payments` (+ itens): cada recebimento de crediário, com o rateio FIFO por
 *   parcela, o recebimento da venda gerado para o principal e a receita financeira dos encargos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->decimal('credit_late_fee_percent', 5, 2)->default(2);
            $table->decimal('credit_monthly_interest_percent', 6, 4)->default(1);
            $table->unsignedSmallInteger('credit_grace_days')->default(0);
        });

        Schema::create('client_credit_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->constrained('users');
            $table->foreignId('sale_id')->unique()->constrained();
            $table->decimal('total', 14, 2);
            $table->unsignedSmallInteger('installments_count');
            $table->date('first_due_date');
            $table->decimal('late_fee_percent', 5, 2);
            $table->decimal('monthly_interest_percent', 6, 4);
            $table->unsignedSmallInteger('grace_days');
            $table->string('status', 20);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });

        Schema::create('client_credit_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_credit_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('due_date');
            $table->decimal('amount', 14, 2);
            $table->decimal('paid_principal', 14, 2)->default(0);
            $table->decimal('paid_fees', 14, 2)->default(0);
            $table->date('fees_settled_on')->nullable();
            $table->decimal('open_fees_paid', 14, 2)->default(0);
            $table->string('status', 20);
            $table->timestamps();

            $table->unique(['client_credit_plan_id', 'number']);
            $table->index(['due_date', 'status']);
        });

        Schema::create('client_credit_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->constrained('users');
            $table->foreignId('payment_method_id')->constrained();
            $table->date('paid_on');
            $table->decimal('amount', 14, 2);
            $table->decimal('principal_amount', 14, 2);
            $table->decimal('fees_amount', 14, 2);
            $table->decimal('fee_discount', 14, 2)->default(0);
            $table->foreignId('financial_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'paid_on']);
        });

        Schema::create('client_credit_payment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_credit_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_credit_installment_id')->constrained();
            $table->decimal('principal', 14, 2);
            $table->decimal('fees', 14, 2);
            $table->decimal('fee_discount', 14, 2)->default(0);
            $table->foreignId('sale_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE organizations ADD CONSTRAINT organizations_credit_late_fee_check CHECK (credit_late_fee_percent >= 0 AND credit_late_fee_percent <= 2)');
            DB::statement('ALTER TABLE organizations ADD CONSTRAINT organizations_credit_interest_check CHECK (credit_monthly_interest_percent >= 0 AND credit_monthly_interest_percent <= 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_credit_payment_items');
        Schema::dropIfExists('client_credit_payments');
        Schema::dropIfExists('client_credit_installments');
        Schema::dropIfExists('client_credit_plans');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_credit_late_fee_check');
            DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_credit_interest_check');
        }

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['credit_late_fee_percent', 'credit_monthly_interest_percent', 'credit_grace_days']);
        });
    }
};
