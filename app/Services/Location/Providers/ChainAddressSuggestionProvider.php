<?php

namespace App\Services\Location\Providers;

use App\Contracts\AddressSuggestionProvider;
use App\DataTransferObjects\Location\AddressSearchQuery;

/**
 * Os provedores de autocomplete ATIVOS, em ordem de prioridade (`services.geocoding.providers`,
 * montada em `AppServiceProvider`): o primeiro que devolver lista NÃO vazia ganha.
 *
 * Lista vazia passa a vez (o Photon pode achar o que o Google não achou), mas é lembrada: se
 * ninguém achou e ao menos um respondeu, o resultado é `[]` ("não existe"); só quando todos
 * falharam é `null` ("não consigo consultar").
 */
final class ChainAddressSuggestionProvider implements AddressSuggestionProvider
{
    /**
     * @param  list<AddressSuggestionProvider>  $providers
     */
    public function __construct(private readonly array $providers) {}

    public function isConfigured(): bool
    {
        return $this->configuredProviders() !== [];
    }

    public function suggest(AddressSearchQuery $query): ?array
    {
        $answer = null;

        foreach ($this->configuredProviders() as $provider) {
            $answer = $provider->suggest($query) ?? $answer;

            if ($answer !== null && $answer !== []) {
                return $answer;
            }
        }

        return $answer;
    }

    /**
     * @return list<AddressSuggestionProvider>
     */
    private function configuredProviders(): array
    {
        return array_values(array_filter(
            $this->providers,
            fn (AddressSuggestionProvider $provider): bool => $provider->isConfigured()
        ));
    }
}
