<?php

namespace App\Services\Medical;

use App\Models\Prescription;

/**
 * Sugestão de dose por peso (`dose_per_kg × peso`) — contrato
 * docs/atendimento-veterinario/03-contrato-receituario.md §3. TRÊS regras inegociáveis do
 * contrato, aplicadas aqui:
 *
 *   1. É sugestão pré-preenchida, nunca valor travado — quando o vet manda `dose_value`
 *      explicitamente, o cálculo nunca sobrescreve, e `dose_calculated` é forçado a `false`
 *      no servidor (não confia no que o cliente mandou nesse campo, só no que ele decidiu
 *      mandar em `dose_value`).
 *   2. Nunca impede prescrever — peso desconhecido ou `dose_per_kg` ausente apenas deixam de
 *      calcular, sem erro.
 *   3. Não é validação farmacológica — é aritmética do que o próprio vet digitou.
 */
final class PrescriptionDoseCalculator
{
    private const ROUND_PRECISION = 3;

    public function __construct(private readonly PrescriptionWeightResolver $weightResolver) {}

    /**
     * @param  array<string, mixed>  $itemData
     * @return array<string, mixed>
     */
    public function resolveDose(Prescription $prescription, array $itemData): array
    {
        if ($this->hasExplicitDoseValue($itemData)) {
            $itemData['dose_calculated'] = false;

            return $itemData;
        }

        return $this->applyCalculatedDose($prescription, $itemData);
    }

    /**
     * @param  array<string, mixed>  $itemData
     */
    private function hasExplicitDoseValue(array $itemData): bool
    {
        return array_key_exists('dose_value', $itemData) && $itemData['dose_value'] !== null;
    }

    /**
     * @param  array<string, mixed>  $itemData
     * @return array<string, mixed>
     */
    private function applyCalculatedDose(Prescription $prescription, array $itemData): array
    {
        $dosePerKg = $itemData['dose_per_kg'] ?? null;
        $weight = $dosePerKg !== null ? $this->weightResolver->resolve($prescription) : null;

        if ($dosePerKg === null || $weight === null) {
            $itemData['dose_calculated'] = false;

            return $itemData;
        }

        $itemData['dose_value'] = round((float) $dosePerKg * $weight, self::ROUND_PRECISION);
        $itemData['dose_calculated'] = true;

        return $itemData;
    }
}
