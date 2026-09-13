<?php

use App\Services\Organization\CommercialOrganizationBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Item 3 do split Pessoa/Organização — migration de DADO, separada da de schema.
 *
 * Idempotente (ver `CommercialOrganizationBackfillService`): pode ser reexecutada com
 * segurança se for interrompida no meio.
 *
 * `down()` é intencionalmente no-op: `organization_id` é enriquecimento de leitura futura,
 * nenhum leitor depende dela ainda (ver a migration de schema) — não há necessidade de
 * reverter o dado, e apagar não desfaria nenhum efeito colateral porque não existe nenhum.
 */
return new class extends Migration
{
    /**
     * Sem transação única envolvendo a migration inteira — o backfill já comita lote a
     * lote (`CommercialOrganizationBackfillService`). Ver o porquê no serviço: uma única
     * transação cobrindo lotes demais reteria locks de linha demais na tabela
     * `organizations` referenciada, repetindo o incidente da Fase 1 por uma via diferente.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $service = app(CommercialOrganizationBackfillService::class);

        $ambiguousOwners = $service->ambiguousOwnerCount();
        $updatedByTable = $service->run();

        // Dono de mais de uma organização não tem `organization_id` inferível: o dado legado
        // registra só a pessoa, não a qual dos negócios dela a linha pertence. Essas linhas ficam
        // nulas de propósito. Registrar o número é o que impede isso de virar lacuna silenciosa —
        // se for maior que zero, alguém precisa decidir caso a caso.
        Log::info('Backfill de organization_id nas tabelas comerciais concluído', [
            'linhas_por_tabela' => $updatedByTable,
            'total' => array_sum($updatedByTable),
            'donos_ambiguos_ignorados' => $ambiguousOwners,
        ]);
    }

    public function down(): void
    {
        // Intencionalmente no-op — ver nota da classe.
    }
};
