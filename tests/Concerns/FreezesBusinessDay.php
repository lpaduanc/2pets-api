<?php

namespace Tests\Concerns;

use Carbon\Carbon;

/**
 * Congela o relógio no dia de hoje às 15h UTC (12h em São Paulo), onde a data UTC e a data de
 * negócio (`App\Support\BusinessClock`) são a mesma. Para testes que montam datas com `now()` /
 * `today()` (UTC) e comparam com regra que decide "hoje" pela data de negócio: sem isto, eles
 * falham quando a suíte roda depois das 21h de Brasília (UTC já no dia seguinte).
 */
trait FreezesBusinessDay
{
    protected function setUpFreezesBusinessDay(): void
    {
        Carbon::setTestNow(Carbon::now('UTC')->setTime(15, 0));
    }

    protected function tearDownFreezesBusinessDay(): void
    {
        Carbon::setTestNow();
    }
}
