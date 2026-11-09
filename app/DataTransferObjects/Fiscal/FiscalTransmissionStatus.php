<?php

namespace App\DataTransferObjects\Fiscal;

use App\Enums\FiscalDocumentKind;

/**
 * Bloco `transmission` do `GET fiscal-settings` — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §6. `mode` é o driver da NF-e (modelo 55); `documents`
 * detalha por modelo (a NFC-e também precisa do CSC); `missing` lista o que falta para emitir de
 * verdade, inclusive dado do emitente que a SEFAZ exige (vazio ⇒ `ready`).
 *
 * `nfse`: bloco próprio da NFS-e pelo Sistema Nacional (spec fase4-transmissao-propria-nfse-nacional
 * §3) — `{mode: national|simulated, ready, missing}`; o `missing` de cima continua o da NF-e/NFC-e.
 */
final readonly class FiscalTransmissionStatus
{
    public const MODE_SIMULATED = 'simulated';

    public const MODE_SEFAZ = 'sefaz';

    public const MODE_NATIONAL = 'national';

    /**
     * @param  array{nfe: string, nfce: string}  $documents
     * @param  list<string>  $missing
     * @param  array{mode: string, ready: bool, missing: list<string>}|null  $nfse
     */
    public function __construct(public array $documents, public array $missing, public ?string $environment, public ?array $nfse = null) {}

    public function mode(): string
    {
        return $this->documents[FiscalDocumentKind::NFE->value];
    }

    /** NFS-e vai ao Sistema Nacional (A1 válido + `fiscal.nfse.enabled`). */
    public function usesNationalNfse(): bool
    {
        return ($this->nfse['mode'] ?? self::MODE_SIMULATED) === self::MODE_NATIONAL;
    }

    public function usesSefaz(FiscalDocumentKind $kind): bool
    {
        return ($this->documents[$kind->value] ?? self::MODE_SIMULATED) === self::MODE_SEFAZ;
    }

    /** @return array{mode: string, ready: bool, missing: list<string>, documents: array{nfe: string, nfce: string}, environment: string|null, nfse: array<string, mixed>|null} */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode(),
            'ready' => $this->missing === [],
            'missing' => $this->missing,
            'documents' => $this->documents,
            'environment' => $this->environment,
            'nfse' => $this->nfse,
        ];
    }
}
