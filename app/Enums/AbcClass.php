<?php

namespace App\Enums;

/**
 * Classificação ABC do cliente — contrato
 * `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`, regra de negócio 3: por
 * PERCENTIL DE CONTAGEM (não Pareto de valor). Ordenado por `total_spent_365d` desc: top 15% =
 * A, próximos 35% = B, restante = C. Decisão explícita — os números do SimplesVet (17/39/56
 * clientes ≈ 15%/35%/50%) batem com percentil de contagem, não com "A = 80% do faturamento".
 */
enum AbcClass: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';

    public function label(): string
    {
        return match ($this) {
            self::A => 'A — mais valiosos',
            self::B => 'B — intermediários',
            self::C => 'C — cauda longa',
        };
    }
}
