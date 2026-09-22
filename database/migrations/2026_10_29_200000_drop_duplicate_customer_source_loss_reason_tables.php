<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Limpeza da duplicação achada entre o item 18 (`client_origins`/`churn_reasons`) e o item 23
 * (`customer_sources`/`loss_reasons`, MESMO conceito) — ver
 * `docs/gap-simplesvet/contratos/18-contrato-api.md`, seção "Consolidação com o item 23".
 *
 * A migration original (`2026_10_22_100000_create_configurable_catalogs_tables`) já foi editada
 * para não criar mais estas duas tabelas em instalações NOVAS, mas já tinha rodado no banco de
 * desenvolvimento compartilhado antes da consolidação — esta migration remove o que sobrou lá,
 * sem dado nenhum (tabelas vazias, criadas na mesma sessão em que foram descontinuadas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('customer_sources');
        Schema::dropIfExists('loss_reasons');
    }

    /**
     * Não reversível: recriar essas tabelas ressuscitaria uma duplicação que já foi
     * deliberadamente eliminada. Quem precisar do conceito usa `client_origins`/`churn_reasons`.
     */
    public function down(): void
    {
        // Intencionalmente vazio — ver docblock da classe.
    }
};
