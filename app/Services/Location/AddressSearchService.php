<?php

namespace App\Services\Location;

use App\Contracts\AddressSuggestionProvider;
use App\DataTransferObjects\Location\AddressSearchQuery;
use App\DataTransferObjects\Location\AddressSearchResult;
use App\DataTransferObjects\Location\AddressSuggestion;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Autocomplete de endereço: cache curto + provedor (cadeia Google Places → Photon, montada em
 * `AppServiceProvider`), traduzido num resultado que diz POR QUE veio vazio.
 *
 * Cache de 1 dia, e não os 30 do geocoding: o autocomplete é chamado a cada letra e repete
 * muito no mesmo dia (o tutor apaga e redigita), mas não vale guardar texto parcial por um
 * mês. Vazio e falha não são cacheados — um provedor que voltar responde na próxima letra.
 */
final class AddressSearchService
{
    private const CACHE_TTL_HOURS = 24;

    public function __construct(private readonly AddressSuggestionProvider $provider) {}

    public function search(AddressSearchQuery $query): AddressSearchResult
    {
        if (! $this->provider->isConfigured()) {
            return AddressSearchResult::unavailable();
        }

        $cached = $this->cache()->get($query->cacheKey());

        if (is_array($cached) && $cached !== []) {
            return AddressSearchResult::found($cached);
        }

        return $this->fromProvider($query);
    }

    private function fromProvider(AddressSearchQuery $query): AddressSearchResult
    {
        $answer = $this->provider->suggest($query);

        if ($answer === null) {
            return AddressSearchResult::unavailable();
        }

        $suggestions = $this->distinct($answer, $query->limit);

        if ($suggestions === []) {
            return AddressSearchResult::notFound();
        }

        $this->cache()->put($query->cacheKey(), $suggestions, now()->addHours(self::CACHE_TTL_HOURS));

        return AddressSearchResult::found($suggestions);
    }

    /**
     * Primeiro de cada (título, subtítulo), até `limit` — ver `AddressSuggestion::deduplicationKey()`.
     *
     * @param  list<AddressSuggestion>  $suggestions
     * @return list<AddressSuggestion>
     */
    private function distinct(array $suggestions, int $limit): array
    {
        return collect($suggestions)
            ->unique(fn (AddressSuggestion $suggestion): string => $suggestion->deduplicationKey())
            ->take($limit)
            ->values()
            ->all();
    }

    private function cache(): Repository
    {
        return Cache::store(config('services.geocoding.cache_store'));
    }
}
