<?php

namespace App\DataTransferObjects\Fiscal;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentOperation;
use App\Enums\FiscalEmissionMode;
use App\Enums\FiscalEnvironment;

/**
 * Payload NEUTRO de emissão — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §0/§5. É o
 * que qualquer driver do `FiscalProviderGateway` recebe; o futuro driver próprio (assina com o A1
 * e envia à SEFAZ) mapeia campo a campo para o layout 4.00:
 *
 * - `kind`/`series`/`number`/`direction`/`environment`/`emissionMode` → `ide` (mod, serie, nNF,
 *   tpNF, tpAmb, tpEmis); `contingencyReason` → `xJust` (com `dhCont`);
 * - `emitter`/`recipient` → `emit`/`dest` (`FiscalParty::toArray`);
 * - `items[]` → `det` (`prod` + `imposto`: ICMS por CST/CSOSN, PIS, COFINS, IPI devolvido, ST);
 * - `totals` → `total/ICMSTot` (vProd, vDesc, vOutro, vBC, vICMS, vFCP, vPIS, vCOFINS, vIPI, vST, vNF);
 * - `references` → `NFref/refNFe` (devoluções).
 *
 * Valores monetários como string de 2 casas; alíquotas e preço unitário com 4.
 */
final readonly class FiscalInvoicePayload
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, string>  $totals
     * @param  list<string>  $references  chaves de acesso referenciadas
     */
    public function __construct(
        public int $documentId,
        public FiscalDocumentKind $kind,
        public FiscalDocumentOperation $operation,
        public FiscalDocumentDirection $direction,
        public FiscalEnvironment $environment,
        public FiscalEmissionMode $emissionMode,
        public ?string $contingencyReason,
        public ?string $series,
        public ?int $number,
        public FiscalParty $emitter,
        public ?FiscalParty $recipient,
        public array $items,
        public array $totals,
        public array $references,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'kind' => $this->kind->value,
            'operation' => $this->operation->value,
            'direction' => $this->direction->value,
            'environment' => $this->environment->value,
            'emission_mode' => $this->emissionMode->value,
            'contingency_reason' => $this->contingencyReason,
            'series' => $this->series,
            'number' => $this->number,
            'emitter' => $this->emitter->toArray(),
            'recipient' => $this->recipient?->toArray(),
            'items' => $this->items,
            'totals' => $this->totals,
            'references' => $this->references,
        ];
    }
}
