<?php

namespace App\Services\Fiscal\Calculation;

use App\Enums\FiscalCfopOperation;
use App\Enums\FiscalCfopScope;
use App\Enums\FiscalDocumentKind;

/**
 * Qual faixa de CFOP do perfil vale para o item — spec docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §3.4. Puro: recebe as faixas do perfil e devolve o CFOP ou `null`
 * (faixa não configurada — quem emite recusa com 422 dizendo qual).
 *
 * NFC-e é sempre operação presencial dentro do estado (D5); destinatário sem UF também.
 */
final class FiscalCfopSelector
{
    public function scope(?string $emitterState, ?string $recipientState, FiscalDocumentKind $kind): FiscalCfopScope
    {
        if ($kind === FiscalDocumentKind::NFCE || self::blank($recipientState) || self::blank($emitterState)) {
            return FiscalCfopScope::INTRASTATE;
        }

        return strtoupper(trim((string) $emitterState)) === strtoupper(trim((string) $recipientState))
            ? FiscalCfopScope::INTRASTATE
            : FiscalCfopScope::INTERSTATE;
    }

    /**
     * @param  array<string, array<string, string>>  $cfops  [operação => [escopo => CFOP]]
     */
    public function select(array $cfops, FiscalCfopOperation $operation, FiscalCfopScope $scope): ?string
    {
        return $cfops[$operation->value][$scope->value] ?? null;
    }

    private static function blank(?string $state): bool
    {
        return $state === null || trim($state) === '';
    }
}
