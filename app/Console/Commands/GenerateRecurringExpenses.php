<?php

namespace App\Console\Commands;

use App\Services\Wallet\RecurringExpenseService;
use App\Services\Wallet\WalletCalendar;
use Illuminate\Console\Command;

/** Lança na Carteira os gastos recorrentes que venceram até hoje (fuso do tutor). */
class GenerateRecurringExpenses extends Command
{
    protected $signature = 'wallet:generate-recurring';

    protected $description = 'Lança os gastos recorrentes da Carteira do tutor que venceram até hoje';

    public function handle(RecurringExpenseService $recurring): int
    {
        $created = $recurring->generateDue(WalletCalendar::today());
        $this->info("Gastos recorrentes lançados: {$created}");

        return self::SUCCESS;
    }
}
