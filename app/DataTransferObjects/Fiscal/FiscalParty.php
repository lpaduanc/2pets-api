<?php

namespace App\DataTransferObjects\Fiscal;

use App\Support\TaxpayerDocument;

/**
 * Emitente ou destinatário do documento — grupos `emit`/`dest` da NF-e, neutros de provedor
 * (spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §0). Documento sem máscara: CPF só
 * dígitos, CNPJ em maiúsculas (pode ter letra — CNPJ alfanumérico).
 *
 * @phpstan-type Address array{street: string|null, number: string|null, complement: string|null, district: string|null, city: string|null, state: string|null, zip: string|null}
 */
final readonly class FiscalParty
{
    /** @param  Address  $address */
    public function __construct(
        public string $name,
        public ?string $document,
        public array $address,
        public ?string $stateRegistration = null,
        public ?string $stateRegistrationType = null,
        public ?string $municipalRegistration = null,
        public ?string $taxRegime = null,
        public ?string $email = null,
    ) {}

    public function state(): ?string
    {
        return $this->address['state'] ?? null;
    }

    public function documentType(): ?string
    {
        return TaxpayerDocument::type($this->document);
    }

    /**
     * O que falta para este destinatário receber uma NF-e (CPF/CNPJ e endereço completo).
     *
     * @return list<string>
     */
    public function missingForNfe(): array
    {
        $required = [
            'CPF/CNPJ' => $this->documentType(),
            'logradouro' => $this->address['street'] ?? null,
            'cidade' => $this->address['city'] ?? null,
            'UF' => $this->address['state'] ?? null,
            'CEP' => $this->address['zip'] ?? null,
        ];

        return array_keys(array_filter($required, fn (?string $value): bool => $value === null || trim($value) === ''));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'document' => $this->document,
            'document_type' => $this->documentType(),
            'state_registration' => $this->stateRegistration,
            'state_registration_type' => $this->stateRegistrationType,
            'municipal_registration' => $this->municipalRegistration,
            'tax_regime' => $this->taxRegime,
            'email' => $this->email,
            'address' => $this->address,
        ];
    }
}
