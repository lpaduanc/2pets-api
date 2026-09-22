<?php

namespace App\Enums;

/**
 * `{indicator}` de `GET insights/{indicator}` — contrato
 * docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md.
 *
 * Decisão de arquitetura ratificada na spec: **não construir um motor de BI genérico**, e sim
 * um conjunto FECHADO de indicadores. `SALES` é o único hoje (dimensão × métrica de venda);
 * um indicador novo (ex.: `clients`, que depende do item 18) é um `case` novo aqui, nunca um
 * parâmetro livre.
 */
enum InsightIndicator: string
{
    case SALES = 'sales';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
