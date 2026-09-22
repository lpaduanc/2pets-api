<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6 do fluxo de agendamento: configuração de sinal (pagamento parcial antecipado) no
 * nível do ESTABELECIMENTO. "Estabelecimento" cobre dois modelos diferentes por design
 * (Fase 1 já fixou este invariante — vet volante NUNCA ganha `Organization` fictícia):
 *
 * - Organização (clínica/petshop com equipe) → `organizations.deposit_enabled`/
 *   `deposit_percentage`.
 * - Profissional autônomo (sem organização) → `professionals.deposit_enabled`/
 *   `deposit_percentage` — o mesmo model que já guarda `opening_hours`/`closing_hours`/
 *   `service_radius_km` para quem não tem organização (mesma duplicação de campo que já
 *   existe entre as duas tabelas para toda outra configuração de negócio).
 *
 * `deposit_enabled` boolean simples aqui (não tri-state): é o NÍVEL BASE da resolução —
 * default `false` porque sinal é opcional e desligado por padrão (decisão do dono do
 * produto). O override tri-state fica em `services` (migration irmã).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('deposit_enabled')->default(false)->after('service_radius_km');
            $table->decimal('deposit_percentage', 5, 2)->nullable()->after('deposit_enabled');
        });

        Schema::table('professionals', function (Blueprint $table): void {
            $table->boolean('deposit_enabled')->default(false)->after('service_radius_km');
            $table->decimal('deposit_percentage', 5, 2)->nullable()->after('deposit_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['deposit_enabled', 'deposit_percentage']);
        });

        Schema::table('professionals', function (Blueprint $table): void {
            $table->dropColumn(['deposit_enabled', 'deposit_percentage']);
        });
    }
};
