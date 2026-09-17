<?php

namespace App\Services\Medical;

use App\Models\Prescription;

/**
 * Substitui por completo os itens de uma prescrição — nunca faz merge parcial. O contrato
 * (§2) não prevê "atualizar item 3 mantendo os outros": a tela sempre reenvia a lista inteira,
 * e sem soft delete em `prescription_items` não há histórico a preservar enquanto a
 * prescrição ainda é editável.
 */
final class PrescriptionItemsWriter
{
    public function __construct(private readonly PrescriptionDoseCalculator $doseCalculator) {}

    /**
     * @param  list<array<string, mixed>>  $itemsData
     */
    public function replace(Prescription $prescription, array $itemsData): void
    {
        $prescription->items()->delete();

        foreach (array_values($itemsData) as $index => $itemData) {
            $resolved = $this->doseCalculator->resolveDose($prescription, $itemData);
            $resolved['position'] = $index + 1;

            $prescription->items()->create($resolved);
        }
    }
}
