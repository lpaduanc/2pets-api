<?php

namespace App\Jobs;

use App\Enums\Location\GeocodingStatus;
use App\Exceptions\Location\GeocodingFailedException;
use App\Models\User;
use App\Services\Location\UserAddressGeocoder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reprocessa o endereço de um usuário cujo geocoding falhou no cadastro/edição
 * (`users.geocoding_status = failed`). Wrapper assíncrono: a regra mora em
 * `UserAddressGeocoder`.
 *
 * Idempotente: se o endereço já foi resolvido (por outra tentativa ou por uma nova edição),
 * não faz nada. Esgotadas as tentativas, a conta continua `failed` e volta a ser recolhida
 * pelo `geocoding:retry-failed`.
 */
final class GeocodeUserAddress implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @var list<int> segundos: 1 min, 10 min, 1 h */
    public array $backoff = [60, 600, 3600];

    public function __construct(private readonly int $userId) {}

    public function handle(UserAddressGeocoder $addressGeocoder): void
    {
        $user = User::find($this->userId);

        if ($user?->geocoding_status !== GeocodingStatus::FAILED || ! $addressGeocoder->isConfigured()) {
            return;
        }

        if (! $addressGeocoder->geocodeStoredAddress($user)) {
            throw GeocodingFailedException::forUser($this->userId);
        }
    }
}
