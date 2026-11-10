<?php

namespace App\Services\Fiscal;

use App\DataTransferObjects\Cnpj;
use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\DataTransferObjects\Fiscal\FiscalParty;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use App\Support\TaxpayerDocument;

/**
 * Monta emitente e destinatário neutros (`FiscalParty`) a partir do cadastro — spec
 * docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §0. Emitente: a organização (ou o próprio
 * profissional, vet volante). Destinatário: o cliente da venda ou o fornecedor da compra.
 */
final class FiscalPartyResolver
{
    public function emitter(FiscalOwner $owner): FiscalParty
    {
        if ($owner->organizationId !== null) {
            return $this->fromOrganization(Organization::findOrFail($owner->organizationId));
        }

        return $this->fromUser(User::findOrFail($owner->professionalId));
    }

    public function client(?User $client): ?FiscalParty
    {
        return $client === null ? null : $this->fromUser($client);
    }

    public function supplier(Supplier $supplier): FiscalParty
    {
        return new FiscalParty(
            name: (string) ($supplier->legal_name ?: $supplier->trade_name),
            document: TaxpayerDocument::normalize($supplier->document),
            address: [
                'street' => $supplier->address_street,
                'number' => $supplier->address_number,
                'complement' => $supplier->address_complement,
                'district' => $supplier->address_district,
                'city' => $supplier->address_city,
                'state' => $supplier->address_state,
                'zip' => self::digits($supplier->address_zip),
            ],
            stateRegistration: $supplier->state_registration,
            email: $supplier->email,
        );
    }

    private function fromOrganization(Organization $organization): FiscalParty
    {
        return new FiscalParty(
            name: (string) $organization->business_name,
            document: Cnpj::normalizeForStorage($organization->cnpj),
            address: self::address($organization->only(['address', 'number', 'complement', 'neighborhood', 'city', 'state', 'zip_code'])),
            stateRegistration: $organization->state_registration,
            stateRegistrationType: $organization->state_registration_type?->value,
            municipalRegistration: $organization->municipal_registration,
            taxRegime: $organization->tax_regime?->value,
        );
    }

    private function fromUser(User $user): FiscalParty
    {
        return new FiscalParty(
            name: (string) $user->name,
            document: TaxpayerDocument::normalize($user->cnpj ?: $user->cpf),
            address: self::address($user->only(['address', 'number', 'complement', 'neighborhood', 'city', 'state', 'zip_code'])),
            stateRegistration: $user->state_registration,
            stateRegistrationType: $user->state_registration_type?->value,
            municipalRegistration: $user->municipal_registration,
            taxRegime: $user->tax_regime?->value,
            email: $user->email,
        );
    }

    /**
     * @param  array<string, mixed>  $source  colunas de endereço de `organizations`/`users`
     * @return array{street: string|null, number: string|null, complement: string|null, district: string|null, city: string|null, state: string|null, zip: string|null}
     */
    private static function address(array $source): array
    {
        return [
            'street' => $source['address'] ?? null,
            'number' => $source['number'] ?? null,
            'complement' => $source['complement'] ?? null,
            'district' => $source['neighborhood'] ?? null,
            'city' => $source['city'] ?? null,
            'state' => isset($source['state']) ? strtoupper((string) $source['state']) : null,
            'zip' => self::digits($source['zip_code'] ?? null),
        ];
    }

    private static function digits(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }
}
