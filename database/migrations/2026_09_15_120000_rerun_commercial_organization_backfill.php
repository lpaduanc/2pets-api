<?php

use App\Services\Organization\CommercialOrganizationBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * REGRESSÃO (relatada pelo coordenador em dev, 2026-09-15): `InventoryController` e
 * `ClinicalStockDeductionService` passaram a escopar `inventories` por `organization_id`
 * (docs/vinculo-estoque-aplicacao-clinica.md item 5), mas a migration
 * `2026_09_14_100100_backfill_organization_id_on_commercial_tables` só preencheu
 * `organization_id` UMA VEZ, na data em que rodou. Qualquer linha comercial criada DEPOIS
 * daquele backfill e ANTES do escopo por organização entrar em vigor nasceu órfã:
 * `organization_id` NULL + `professional_id` de um dono que JÁ tinha organização ativa —
 * medido em dev: 6 de 12 itens de `inventories` (50%), exatamente os do dono com organização.
 *
 * `CommercialOrganizationBackfillService` já resolve exatamente isso e é idempotente (filtra
 * `organization_id IS NULL`, então reexecutá-lo só toca o que ainda falta — nenhuma linha já
 * preenchida muda). Reaproveitado tal como está, sem duplicar a regra de prioridade "dono de
 * exatamente uma organização" (a mesma que `InventoryScopeResolver::primaryOrganizationId()`
 * usa) num segundo lugar. Roda para as 17 tabelas do grupo COMERCIAL, não só `inventories`:
 * qualquer uma delas pode ter nascido órfã na mesma janela, pelo mesmo motivo, e o serviço já
 * varre todas com o custo de uma única execução — não há razão para reduzir o escopo aqui.
 *
 * `InventoryScopeResolver::scopeQuery()` ganhou, à parte deste backfill, uma rede de segurança
 * para o caso que NENHUM backfill automático resolve (dono de mais de uma organização, deixado
 * `NULL` de propósito por ambiguidade) — ver o comentário do método.
 */
return new class extends Migration
{
    /**
     * Sem transação única envolvendo a migration inteira — mesmo motivo da migration original
     * do backfill: o serviço já comita lote a lote, e uma transação cobrindo lotes demais
     * reteria locks de linha demais na tabela `organizations` referenciada.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $service = app(CommercialOrganizationBackfillService::class);

        $ambiguousOwners = $service->ambiguousOwnerCount();
        $updatedByTable = $service->run();

        Log::info('Reexecução do backfill de organization_id (correção da regressão de escopo de estoque)', [
            'linhas_por_tabela' => $updatedByTable,
            'total' => array_sum($updatedByTable),
            'donos_ambiguos_ignorados' => $ambiguousOwners,
        ]);
    }

    public function down(): void
    {
        // Intencionalmente no-op — mesma justificativa da migration original: backfill é
        // enriquecimento de dado real, não há efeito colateral a desfazer.
    }
};
