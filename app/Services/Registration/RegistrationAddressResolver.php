<?php

namespace App\Services\Registration;

use App\Models\User;
use App\Services\Location\UserAddressGeocoder;

/**
 * Endereço + geocodificação compartilhados pelos fluxos de conclusão de cadastro que
 * geocodificam (tutor, veterinário e profissional genérico — `completeCompany` não usa
 * esta classe, ver nota em `RegistrationCompletionService::completeCompany()`).
 *
 * Geocodificação nunca interrompe o cadastro: se falhar, o endereço é salvo com
 * `geocoding_status = failed` e o reprocessamento é enfileirado (`UserAddressGeocoder`).
 */
final class RegistrationAddressResolver
{
    public function __construct(private readonly UserAddressGeocoder $addressGeocoder) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function addressData(array $data): array
    {
        return [
            'address' => $data['address'],
            'number' => $data['number'],
            'complement' => $data['complement'] ?? null,
            'neighborhood' => $data['neighborhood'],
            'city' => $data['city'],
            'state' => $data['state'],
            'zip_code' => $data['zip_code'],
        ];
    }

    /**
     * Prefere coordenadas do Google Places (capturadas no autocomplete — mais precisas que
     * re-geocodificar o endereço em texto livre). Cai para geocodificação no servidor.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function coordinatesPreferringRequest(array $data): array
    {
        if (isset($data['latitude'], $data['longitude'])) {
            return $this->addressGeocoder->resolvedAttributes((float) $data['latitude'], (float) $data['longitude']);
        }

        return $this->geocodedCoordinates($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function geocodedCoordinates(array $data): array
    {
        return $this->addressGeocoder->coordinateAttributes($data);
    }

    /** Chamado depois de gravar o usuário: enfileira o reprocessamento se o geocoding falhou. */
    public function scheduleRetryIfFailed(User $user): void
    {
        $this->addressGeocoder->scheduleRetryIfFailed($user);
    }
}
