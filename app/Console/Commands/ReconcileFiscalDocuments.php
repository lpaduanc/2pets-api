<?php

namespace App\Console\Commands;

use App\Services\Fiscal\FiscalContingencyService;
use Illuminate\Console\Command;

/**
 * Reconciliação fiscal (spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §4): consulta no
 * provedor os documentos processando/em contingência e reenvia os pendentes com retentativa
 * vencida. Idempotente — estado final não muda; falha de transporte só reagenda.
 */
class ReconcileFiscalDocuments extends Command
{
    protected $signature = 'fiscal:reconcile';

    protected $description = 'Consulta documentos fiscais processando/em contingência e reenvia os pendentes vencidos.';

    public function handle(FiscalContingencyService $contingency): int
    {
        $summary = $contingency->reconcile();
        $this->info('Documentos reconciliados: '.(array_sum($summary) === 0 ? 'nenhum' : json_encode($summary)).'.');

        return self::SUCCESS;
    }
}
