<?php

namespace App\DataTransferObjects\Fiscal;

use App\Enums\FiscalCertificateMatch;
use Carbon\CarbonImmutable;

/**
 * Metadados PÚBLICOS de um A1 já validado (`FiscalCertificateInspector`) — nada aqui é segredo: o
 * CNPJ, o titular, a AC e as datas estão no certificado público que acompanha toda NF-e assinada.
 */
final readonly class InspectedCertificate
{
    public function __construct(
        public string $subjectDocument,
        public string $subjectName,
        public ?string $issuerName,
        public ?string $serialNumber,
        public string $fingerprint,
        public FiscalCertificateMatch $match,
        public CarbonImmutable $validFrom,
        public CarbonImmutable $validTo,
    ) {}

    /** @return array<string, mixed> colunas de `fiscal_certificates` */
    public function attributes(): array
    {
        return [
            'subject_document' => $this->subjectDocument,
            'subject_name' => $this->subjectName,
            'issuer_name' => $this->issuerName,
            'serial_number' => $this->serialNumber,
            'fingerprint' => $this->fingerprint,
            'cnpj_match' => $this->match->value,
            'valid_from' => $this->validFrom,
            'valid_to' => $this->validTo,
        ];
    }
}
