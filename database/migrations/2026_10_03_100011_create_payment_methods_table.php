<?php

use App\Enums\PaymentDirection;
use App\Enums\PaymentMethodKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/04 — "Formas de recebimento".
 *
 * A forma é POR ADQUIRENTE ("Maquininha Cielo", "Maquininha Rede"), não genérica, porque taxa
 * e prazo de liquidação são de cada uma. `kind` guarda a natureza (cartão de crédito) e
 * `acquirer` o canal (cielo) — separados para que o BI some "cartão" sem `LIKE`.
 *
 * `direction` cumpre a instrução explícita do documento: UMA tabela com flag, não duas tabelas
 * (o Pix é o mesmo Pix para entrar e para sair).
 *
 * `fee_percent`/`fee_fixed` e `settlement_days` são o que alimenta `sale_receipts.operator_fee`
 * e a previsão de depósito — a razão de a tabela existir antes do doc 01.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->string('name');
            $table->string('kind', 30)->default(PaymentMethodKind::CASH->value);
            $table->string('acquirer', 30)->nullable();
            $table->string('direction', 10)->default(PaymentDirection::IN->value);

            $table->foreignId('default_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();

            $table->decimal('fee_percent', 8, 4)->default(0);
            $table->decimal('fee_fixed', 10, 2)->default(0);

            // Dias entre o recebimento e o depósito na conta destino. 0 = imediato
            // (dinheiro, Pix); 30 é o crédito à vista típico no Brasil.
            $table->unsignedSmallInteger('settlement_days')->default(0);
            $table->unsignedSmallInteger('max_installments')->default(1);

            // Ordem de exibição no modal de recebimento do PDV — dinheiro e Pix primeiro,
            // porque são os mais usados no balcão.
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'active', 'direction']);
            $table->index(['professional_id', 'active', 'direction']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $connection = Schema::getConnection();

        $connection->statement(sprintf(
            "ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_kind_check CHECK (kind IN ('%s'))",
            implode("','", PaymentMethodKind::values())
        ));
        $connection->statement(sprintf(
            "ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_direction_check CHECK (direction IN ('%s'))",
            implode("','", PaymentDirection::values())
        ));

        $connection->statement(
            'CREATE UNIQUE INDEX payment_methods_org_name_unique ON payment_methods (organization_id, lower(name)) WHERE deleted_at IS NULL AND organization_id IS NOT NULL'
        );
        $connection->statement(
            'CREATE UNIQUE INDEX payment_methods_professional_name_unique ON payment_methods (professional_id, lower(name)) WHERE deleted_at IS NULL AND organization_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
