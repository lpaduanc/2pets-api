<?php

use App\Services\Organization\OrganizationBackfillService;
use Illuminate\Database\Migrations\Migration;

/**
 * Fase 1 do split Pessoa/Organização — migration de DADO, separada das de schema.
 *
 * Idempotente (ver `OrganizationBackfillService`): pode rodar de novo com segurança se o
 * deploy for interrompido no meio ou se a migration precisar ser reexecutada manualmente.
 *
 * `down()` é intencionalmente no-op: apagar as organizações herdadas destruiria vínculo que
 * pode já ter sido usado por escrita nova depois do deploy (ex.: um segundo membro adicionado
 * à organização). Reverter dado de negócio é decisão manual do time, não automação de rollback.
 */
return new class extends Migration
{
    /**
     * Sem transação única envolvendo a migration inteira.
     *
     * O backfill commita lote a lote (`OrganizationBackfillService`). Envolver tudo numa
     * transação só faria cada lote virar SAVEPOINT e esgotaria os slots de subtransação do
     * Postgres — foi o que derrubou a primeira execução contra o banco de dev, com ~38 mil
     * contas de negócio, em `SQLSTATE[53200] out of shared memory`.
     *
     * Interromper no meio é seguro justamente porque o serviço é idempotente: reexecutar
     * pula quem já tem vínculo `owner` e continua de onde parou.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        app(OrganizationBackfillService::class)->run();
    }

    public function down(): void
    {
        // Intencionalmente no-op — ver nota da classe.
    }
};
