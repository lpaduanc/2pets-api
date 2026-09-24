<?php

namespace App\Console\Commands;

use App\Enums\Location\GeocodingStatus;
use App\Jobs\GeocodeUserAddress;
use App\Models\User;
use App\Services\Location\UserAddressGeocoder;
use Illuminate\Console\Command;

/**
 * `geocoding:retry-failed` — enfileira `GeocodeUserAddress` para todo usuário com endereço
 * salvo sem coordenada. Cobre o que a fila sozinha não cobre: contas que falharam quando o
 * provedor estava sem chave (nada foi enfileirado) e contas cujas tentativas se esgotaram.
 */
class RetryFailedGeocoding extends Command
{
    private const USERS_PER_CHUNK = 500;

    protected $signature = 'geocoding:retry-failed';

    protected $description = 'Reprocessa o geocoding dos endereços salvos sem coordenada.';

    public function handle(UserAddressGeocoder $addressGeocoder): int
    {
        if (! $addressGeocoder->isConfigured()) {
            $this->error('Nenhum provedor de geocoding configurado (GOOGLE_MAPS_API_KEY vazia).');

            return self::FAILURE;
        }

        $this->info("{$this->dispatchRetries()} endereço(s) enfileirado(s) para reprocessamento.");

        return self::SUCCESS;
    }

    private function dispatchRetries(): int
    {
        $dispatched = 0;

        User::query()
            ->where('geocoding_status', GeocodingStatus::FAILED)
            ->select('id')
            ->chunkById(self::USERS_PER_CHUNK, function ($users) use (&$dispatched): void {
                $users->each(fn (User $user) => GeocodeUserAddress::dispatch($user->id));
                $dispatched += $users->count();
            });

        return $dispatched;
    }
}
