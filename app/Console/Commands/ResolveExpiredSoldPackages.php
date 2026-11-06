<?php

namespace App\Console\Commands;

use App\Services\Commercial\Package\SoldPackageExpirationService;
use Illuminate\Console\Command;

/**
 * Resolve o saldo dos pacotes vendidos que venceram (PLANO 2.7): crédito na conta do cliente ou
 * receita de expiração, pela política da organização. Idempotente — pode rodar quantas vezes
 * quiser no dia.
 */
class ResolveExpiredSoldPackages extends Command
{
    protected $signature = 'sold-packages:resolve-expired';

    protected $description = 'Resolve o saldo de pacotes vendidos vencidos (crédito ao cliente ou receita de expiração).';

    public function handle(SoldPackageExpirationService $expiration): int
    {
        $count = $expiration->resolveAll();
        $this->info("Pacotes vencidos resolvidos: {$count}.");

        return self::SUCCESS;
    }
}
