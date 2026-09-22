<?php

use App\Enums\StateRegistrationType;
use App\Enums\TaxRegime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dados fiscais do DESTINATÁRIO pessoa jurídica — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md. Preenchidos pelo próprio
 * cliente PJ no fluxo de perfil dele (fora da organização) quando a nota exige inscrição.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('tax_regime', 30)->nullable();
            $table->string('municipal_registration', 30)->nullable();
            $table->string('state_registration', 30)->nullable();
            $table->string('state_registration_type', 20)->nullable();
            $table->string('foreign_document')->nullable();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::getConnection()->statement(sprintf(
            "ALTER TABLE users ADD CONSTRAINT users_tax_regime_check CHECK (tax_regime IS NULL OR tax_regime IN ('%s'))",
            implode("','", TaxRegime::values())
        ));
        Schema::getConnection()->statement(sprintf(
            "ALTER TABLE users ADD CONSTRAINT users_state_registration_type_check CHECK (state_registration_type IS NULL OR state_registration_type IN ('%s'))",
            implode("','", StateRegistrationType::values())
        ));
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'tax_regime', 'municipal_registration', 'state_registration',
                'state_registration_type', 'foreign_document',
            ]);
        });
    }
};
