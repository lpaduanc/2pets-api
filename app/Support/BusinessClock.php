<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * "Que dia é hoje" para regra de negócio — vencimento, validade, atraso, dias úteis, janelas de
 * relatório. A aplicação roda em UTC (`config('app.timezone')`, que NÃO deve mudar: mexeria em
 * como todo timestamp é gravado e lido); às 21h em São Paulo o UTC já está no dia seguinte, e
 * uma conta que vence hoje apareceria vencida.
 *
 * O dia vem de `config('app.business_timezone')` (America/Sao_Paulo); organização ainda não tem
 * fuso próprio — quando tiver, é aqui que ele entra.
 *
 * `today()` devolve a DATA de negócio como meia-noite no fuso da aplicação: é o formato em que as
 * colunas `date` são lidas (cast `date` → meia-noite UTC), então `due_date->lt(BusinessClock::today())`
 * compara dia com dia. Para timestamps de auditoria continue usando `now()` (UTC).
 */
final class BusinessClock
{
    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'America/Sao_Paulo');
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::todayString(), (string) config('app.timezone'));
    }

    public static function todayString(): string
    {
        return CarbonImmutable::now(self::timezone())->toDateString();
    }

    /** O instante atual no fuso de negócio (para exibir/derivar mês e hora local). */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }
}
