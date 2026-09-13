<?php

namespace App\DataTransferObjects;

use Carbon\Carbon;

/**
 * Fronteiras de data usadas pelo dashboard do profissional, calculadas uma única vez por
 * requisição. Existe para não espalhar `now()` por métodos diferentes (cada chamada pega um
 * instante ligeiramente distinto) e para não estourar o limite de 4 parâmetros por método só
 * para passar "hoje", "amanhã", "ontem", "mês atual" e "mês anterior" adiante.
 */
final readonly class DashboardDateWindow
{
    public Carbon $today;

    public Carbon $tomorrow;

    public Carbon $yesterday;

    public Carbon $thisMonth;

    public Carbon $lastMonth;

    public Carbon $lastMonthEnd;

    public function __construct()
    {
        $this->today = now()->startOfDay();
        $this->tomorrow = $this->today->copy()->addDay();
        $this->yesterday = $this->today->copy()->subDay();
        $this->thisMonth = now()->startOfMonth();
        $this->lastMonth = now()->subMonthNoOverflow()->startOfMonth();
        $this->lastMonthEnd = $this->thisMonth->copy();
    }
}
