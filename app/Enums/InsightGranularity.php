<?php

namespace App\Enums;

/**
 * `granularity` de `GET insights/{indicator}` — só vale para `dimension=date` (as demais
 * dimensões agregam o período inteiro, sem sub-bucket temporal).
 *
 * Regra de negócio 3 da spec: a soma das semanas de um período tem que bater com a soma dos
 * dias do mesmo período. Por isso o agrupamento usa sempre `date_trunc()` no Postgres
 * (`App\Services\Insights\InsightBucketExpression`), nunca agrupamento feito em PHP — evita
 * drift de fuso horário entre os dois cálculos.
 */
enum InsightGranularity: string
{
    case DAY = 'day';
    case WEEK = 'week';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
