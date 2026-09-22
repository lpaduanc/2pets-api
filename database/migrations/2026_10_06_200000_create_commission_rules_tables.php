<?php

use App\Enums\CommissionCalculationBase;
use App\Enums\CommissionScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md — regra de
 * comissão da CLÍNICA ao próprio staff. NÃO confundir com `commissions`/`payouts`
 * (`2025_12_27_211000_create_commission_tables.php`), que é o take rate da PLATAFORMA sobre o
 * profissional — fluxo antigo, intocado (ver o comentário da spec, "Por que não alterar").
 *
 * `staff_id` nullable = regra GERAL da organização (vale para todo mundo, até uma exceção mais
 * específica ganhar). `scope`/`scope_id` dão a segunda dimensão de especificidade (produto >
 * grupo > geral), resolvida por `App\Services\Commercial\CommissionRuleResolver`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();

            // Vínculo (não a pessoa): a mesma pessoa pode ter percentual diferente em duas
            // clínicas — `null` é a regra GERAL da organização.
            $table->foreignId('staff_id')->nullable()->constrained('organization_members')->cascadeOnDelete();

            $table->string('scope', 20)->default(CommissionScope::ALL->value);
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->decimal('percent', 8, 4)->nullable();
            $table->decimal('fixed_amount', 14, 2)->nullable();

            $table->string('calculation_base', 20)->default(CommissionCalculationBase::GROSS->value);

            // Documentado, não honrado como `false` nesta rodada — ver
            // `CommissionSettlementService`, que sempre exige venda paga e recebida (regra de
            // negócio 1, "não é opcional").
            $table->boolean('only_when_received')->default(true);

            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'staff_id', 'scope', 'scope_id', 'active']);
            $table->index(['professional_id', 'staff_id', 'scope', 'scope_id', 'active']);
        });

        Schema::create('commission_rule_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commission_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->constrained('users');
            $table->string('field_changed', 60);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('commission_rule_id');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $connection = Schema::getConnection();

        $connection->statement(sprintf(
            "ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_scope_check CHECK (scope IN ('%s'))",
            implode("','", CommissionScope::values())
        ));
        $connection->statement(sprintf(
            "ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_calculation_base_check CHECK (calculation_base IN ('%s'))",
            implode("','", CommissionCalculationBase::values())
        ));

        // Pelo menos um dos dois valores de comissão — regra explícita do modelo de dados.
        $connection->statement(
            'ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_value_check CHECK (percent IS NOT NULL OR fixed_amount IS NOT NULL)'
        );

        // `scope != all` sem `scope_id` é regra incompleta — "comissão de produto sem dizer
        // QUAL produto" não é um cadastro válido.
        $connection->statement(
            "ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_scope_id_check CHECK (scope = 'all' OR scope_id IS NOT NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rule_logs');
        Schema::dropIfExists('commission_rules');
    }
};
