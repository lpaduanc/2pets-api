<?php

namespace App\DataTransferObjects\Location;

/**
 * O que o tutor digitou no autocomplete de endereço, mais o ponto (opcional) que só ENVIESA o
 * resultado para perto — nunca o restringe: quem está viajando busca o endereço de destino.
 */
final readonly class AddressSearchQuery
{
    /** 2 casas ≈ 1,1 km: perto o bastante para o viés, largo o bastante para o cache acertar. */
    private const CACHE_COORDINATE_PRECISION = 2;

    /**
     * Quantos itens pedir ao provedor por item devolvido: o Photon traz um item por TRECHO de
     * rua do OSM, e cinco trechos da mesma rua viram uma sugestão só depois da deduplicação
     * (`AddressSearchService`). Pedir o dobro ainda devolve até `limit` endereços distintos.
     */
    private const PROVIDER_LIMIT_FACTOR = 2;

    public function __construct(
        public string $text,
        public ?float $latitude,
        public ?float $longitude,
        public int $limit,
    ) {}

    public function providerLimit(): int
    {
        return $this->limit * self::PROVIDER_LIMIT_FACTOR;
    }

    public function hasBias(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Mesma busca → mesma chave: texto sem caixa nem espaço repetido e coordenada arredondada.
     */
    public function cacheKey(): string
    {
        $text = preg_replace('/\s+/u', ' ', mb_strtolower(trim($this->text)));
        $bias = $this->hasBias()
            ? round($this->latitude, self::CACHE_COORDINATE_PRECISION).','.round($this->longitude, self::CACHE_COORDINATE_PRECISION)
            : 'none';

        return 'geocoding:address-search:'.md5("{$text}|{$bias}|{$this->limit}");
    }
}
