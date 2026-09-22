<?php

namespace App\Services\Commercial;

use App\Models\Sale;

/**
 * "Quem está orçando" — cabeçalho do PDF e da página pública do orçamento (doc 24: "PDF com
 * identidade visual da clínica").
 *
 * Com organização, é a clínica (razão social, CNPJ, endereço). Sem organização é o vet volante,
 * e o cabeçalho vira o nome comercial dele (ou o nome da pessoa) com o CRMV no lugar do CNPJ.
 * Um só lugar para as duas saídas não divergirem.
 */
final class QuoteIssuer
{
    /**
     * @return array{name: string, document: ?string, address: ?string, city: ?string, state: ?string, phone: ?string}
     */
    public static function for(Sale $quote): array
    {
        $quote->loadMissing(['organization', 'professional.professional']);

        $organization = $quote->organization;

        if ($organization !== null) {
            $street = trim(implode(', ', array_filter([
                $organization->address,
                $organization->number,
                $organization->neighborhood,
            ])));

            return [
                'name' => $organization->business_name,
                'document' => $organization->cnpj ? 'CNPJ '.$organization->cnpj : null,
                'address' => $street !== '' ? $street : null,
                'city' => $organization->city,
                'state' => $organization->state,
                'phone' => $quote->professional?->phone,
            ];
        }

        $user = $quote->professional;
        $profile = $user?->professional;

        return [
            'name' => $profile?->business_name ?: ($user?->name ?? 'Profissional'),
            'document' => $profile?->crmv ? 'CRMV '.$profile->crmv.($profile->crmv_state ? '/'.$profile->crmv_state : '') : null,
            'address' => $user?->address,
            'city' => $user?->city,
            'state' => $user?->state ?? null,
            'phone' => $user?->phone,
        ];
    }
}
