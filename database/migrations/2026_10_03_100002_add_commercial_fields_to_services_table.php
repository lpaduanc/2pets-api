<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/08-produtos-precificacao-lista-precos.md — alterações em `services`.
 *
 * Serviço e produto ficam em TABELAS SEPARADAS de propósito (recomendação do próprio documento):
 * a identificação fiscal de um é NCM (mercadoria, ICMS) e a do outro é código LC116 (serviço,
 * ISS municipal) — unificar obrigaria metade das colunas a viver nula. O que eles compartilham
 * é a INTERFACE (`App\Contracts\Sellable`), consumida por `sale_items` via relação polimórfica.
 *
 * `services.category` continua sendo o `ServiceCategory` clínico (consulta, cirurgia, vacina…),
 * que é dimensão de AGENDA e prontuário; `product_group_id` é a dimensão COMERCIAL do BI. São
 * eixos diferentes e por isso convivem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->foreignId('product_group_id')->nullable()->after('category')->constrained()->nullOnDelete();
            $table->string('code', 60)->nullable()->after('name');
            $table->decimal('commission_percent', 8, 4)->nullable()->after('price');
            $table->string('municipal_service_code', 20)->nullable()->after('commission_percent');
            $table->string('lc116_code', 10)->nullable()->after('municipal_service_code');
            $table->boolean('show_in_price_list')->default(true)->after('lc116_code');
            $table->boolean('allow_price_override')->default(true)->after('show_in_price_list');
        });

        // `duration` já existe e cumpre o papel de `default_duration_minutes` do documento —
        // não duplicamos a coluna só para casar o nome com o SimplesVet.
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_group_id');
            $table->dropColumn([
                'code', 'commission_percent', 'municipal_service_code',
                'lc116_code', 'show_in_price_list', 'allow_price_override',
            ]);
        });
    }
};
