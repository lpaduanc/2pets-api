<?php

namespace App\Enums;

/**
 * Vocabulário de `inventory_movements.type` — enum PHP sem CHECK constraint no banco. Decisão
 * do pet-business-specialist (docs/vinculo-estoque-aplicacao-clinica.md item 8): vocabulário
 * novo, ainda sem uso real, alto risco de precisar de um caso a mais em breve; CHECK constraint
 * trava do jeito que já travou `professional_type` (ver docs/taxonomia-professional-type.md).
 */
enum InventoryMovementType: string
{
    case OUT_VACCINATION = 'out_vaccination';
    case OUT_DEWORMING = 'out_deworming';
    case ADJUSTMENT_INCREASE = 'adjustment_increase';
    case ADJUSTMENT_DECREASE = 'adjustment_decrease';
    case PURCHASE_IN = 'purchase_in';
    case LOSS = 'loss';

    public function label(): string
    {
        return match ($this) {
            self::OUT_VACCINATION => 'Baixa por vacinação',
            self::OUT_DEWORMING => 'Baixa por vermifugação',
            self::ADJUSTMENT_INCREASE => 'Ajuste manual (entrada)',
            self::ADJUSTMENT_DECREASE => 'Ajuste manual (saída)',
            self::PURCHASE_IN => 'Entrada de estoque',
            self::LOSS => 'Perda',
        };
    }
}
