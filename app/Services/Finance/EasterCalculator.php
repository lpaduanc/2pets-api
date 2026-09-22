<?php

namespace App\Services\Finance;

use Carbon\CarbonImmutable;

/**
 * Domingo de Páscoa e os feriados móveis que dele derivam — contrato
 * docs/gap-simplesvet/03-contas-a-pagar-receber-spec.md. Algoritmo de Meeus/Jones/Butcher
 * (calendário gregoriano), o padrão para calcular a Páscoa sem tabela de consulta.
 *
 * Classe pura (sem I/O): o teste fixa os números para os anos do critério de aceite (2026,
 * 2027) sem tocar banco.
 */
final class EasterCalculator
{
    public function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $numerator = $h + $l - 7 * $m + 114;

        return CarbonImmutable::create($year, intdiv($numerator, 31), ($numerator % 31) + 1);
    }

    /** Terça-feira de Carnaval — 47 dias antes da Páscoa. */
    public function carnival(int $year): CarbonImmutable
    {
        return $this->easterSunday($year)->subDays(47);
    }

    /** Sexta-feira Santa — 2 dias antes da Páscoa. */
    public function goodFriday(int $year): CarbonImmutable
    {
        return $this->easterSunday($year)->subDays(2);
    }

    /** Corpus Christi — 60 dias depois da Páscoa. */
    public function corpusChristi(int $year): CarbonImmutable
    {
        return $this->easterSunday($year)->addDays(60);
    }
}
