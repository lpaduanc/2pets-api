<?php

use App\Enums\StateRegistrationType;
use App\Enums\TaxRegime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dados fiscais do EMITENTE — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md.
 *
 * Vão em `organizations`, NÃO em `companies` (correção explícita da spec ao doc original do
 * backlog: `App\Models\Company` é o rascunho de cadastro/registro, `Organization` é quem
 * vende/fatura/tem caixa — o mesmo tenant que `CommercialScopeResolver` usa em todo o módulo
 * comercial 01/04/06/07/08/24).
 *
 * `certificate_ref` é só a REFERÊNCIA no cofre externo (nunca o `.pfx`) — o fluxo de upload
 * fica com o `security-specialist`; esta migration só reserva a coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('tax_regime', 30)->nullable();
            $table->string('municipal_registration', 30)->nullable();
            $table->string('state_registration', 30)->nullable();
            $table->string('state_registration_type', 20)->nullable();
            $table->string('cnae_code', 10)->nullable();
            $table->string('special_tax_regime')->nullable();
            $table->decimal('iss_rate', 5, 2)->nullable();
            $table->string('certificate_ref')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::getConnection()->statement(sprintf(
            "ALTER TABLE organizations ADD CONSTRAINT organizations_tax_regime_check CHECK (tax_regime IS NULL OR tax_regime IN ('%s'))",
            implode("','", TaxRegime::values())
        ));
        Schema::getConnection()->statement(sprintf(
            "ALTER TABLE organizations ADD CONSTRAINT organizations_state_registration_type_check CHECK (state_registration_type IS NULL OR state_registration_type IN ('%s'))",
            implode("','", StateRegistrationType::values())
        ));
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn([
                'tax_regime', 'municipal_registration', 'state_registration',
                'state_registration_type', 'cnae_code', 'special_tax_regime',
                'iss_rate', 'certificate_ref', 'certificate_expires_at',
            ]);
        });
    }
};
