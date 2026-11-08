<?php

namespace App\DataTransferObjects\Fiscal;

use App\Enums\FiscalDocumentKind;

/**
 * Bloco `transmission` do `GET fiscal-settings` — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §6. `mode` é o driver da NF-e (modelo 55); `documents`
 * detalha por modelo (a NFC-e também precisa do CSC); `missing` lista o que falta para emitir de
 * verdade, inclusive dado do emitente que a SEFAZ exige (vazio ⇒ `ready`).
 */
final readonly class FiscalTransmissionStatus
{
    public const MODE_SIMULATED = 'simulated';

    public const MODE_SEFAZ = 'sefaz';

    /**
     * @param  array{nfe: string, nfce: string}  $documents
     * @param  list<string>  $missing
     */
    public function __construct(public array $documents, public array $missing, public ?string $environment) {}

    public function mode(): string
    {
        return $this->documents[FiscalDocumentKind::NFE->value];
    }

    public function usesSefaz(FiscalDocumentKind $kind): bool
    {
        return ($this->documents[$kind->value] ?? self::MODE_SIMULATED) === self::MODE_SEFAZ;
    }

    /** @return array{mode: string, ready: bool, missing: list<string>, documents: array{nfe: string, nfce: string}, environment: string|null} */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode(),
            'ready' => $this->missing === [],
            'missing' => $this->missing,
            'documents' => $this->documents,
            'environment' => $this->environment,
        ];
    }
}
