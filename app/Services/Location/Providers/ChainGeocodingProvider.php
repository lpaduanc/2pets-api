<?php

namespace App\Services\Location\Providers;

use App\Contracts\GeocodingProvider;

/**
 * Os provedores de geocoding ATIVOS, em ordem de prioridade: o primeiro que responder ganha.
 *
 * Quem entra na lista (e em que ordem) é decidido em `AppServiceProvider` a partir de
 * `services.geocoding.providers` — ligar/desligar um serviço é config, não código. Um
 * provedor ativo mas sem configuração (ex.: Google sem chave) é pulado sem ir à rede, porque
 * cada implementação já devolve `null` nesse caso.
 *
 * `null` do contrato não distingue "não achei" de "falhei" (ver `GeocodingProvider`), então
 * os dois passam a vez para o próximo — inclusive um ponto no oceano, que custa uma consulta
 * a mais e nada além disso.
 */
final class ChainGeocodingProvider implements GeocodingProvider
{
    /**
     * @param  list<GeocodingProvider>  $providers
     */
    public function __construct(private readonly array $providers) {}

    public function isConfigured(): bool
    {
        return $this->configuredProviders() !== [];
    }

    public function geocode(string $address): ?array
    {
        return $this->firstAnswer(fn (GeocodingProvider $provider): ?array => $provider->geocode($address));
    }

    public function reverseGeocode(float $latitude, float $longitude): ?array
    {
        return $this->firstAnswer(fn (GeocodingProvider $provider): ?array => $provider->reverseGeocode($latitude, $longitude));
    }

    /**
     * @param  callable(GeocodingProvider): ?array<string, mixed>  $ask
     * @return array<string, mixed>|null
     */
    private function firstAnswer(callable $ask): ?array
    {
        foreach ($this->configuredProviders() as $provider) {
            $answer = $ask($provider);

            if ($answer !== null) {
                return $answer;
            }
        }

        return null;
    }

    /**
     * @return list<GeocodingProvider>
     */
    private function configuredProviders(): array
    {
        return array_values(array_filter(
            $this->providers,
            fn (GeocodingProvider $provider): bool => $provider->isConfigured()
        ));
    }
}
