<?php

namespace App\Services\Stock;

/**
 * Custo médio ponderado — docs/gap-simplesvet/06 ("manter custo médio ponderado em
 * `products.average_cost`"). Classe pura, sem banco, para o `AverageCostTest` poder fixar os
 * números.
 *
 * Saldo negativo (venda sem estoque, permitido no balcão) conta como ZERO na ponderação: as
 * unidades "devidas" não têm custo registrado, e ponderar por um saldo negativo produziria
 * custo médio negativo ou explodido.
 */
final class AverageCostCalculator
{
    public function afterEntry(int $currentQuantity, float $currentAverage, int $enteringQuantity, float $enteringUnitCost): float
    {
        $base = max(0, $currentQuantity);

        if ($base + $enteringQuantity <= 0) {
            return round($enteringUnitCost, 4);
        }

        return round((($base * $currentAverage) + ($enteringQuantity * $enteringUnitCost)) / ($base + $enteringQuantity), 4);
    }

    /**
     * Desfaz uma entrada (compra cancelada). Quando o que sobra é zero ou a conta fica
     * negativa — as unidades daquela compra já foram vendidas —, o custo médio vigente é
     * mantido: não há como "desmisturar" um custo que já saiu no custo das vendas.
     */
    public function afterReversal(int $currentQuantity, float $currentAverage, int $leavingQuantity, float $leavingUnitCost): float
    {
        $remaining = $currentQuantity - $leavingQuantity;

        if ($remaining <= 0) {
            return round($currentAverage, 4);
        }

        $value = ($currentQuantity * $currentAverage) - ($leavingQuantity * $leavingUnitCost);

        if ($value < 0) {
            return round($currentAverage, 4);
        }

        return round($value / $remaining, 4);
    }
}
