<?php

namespace App\Enums\Location;

/**
 * O que aconteceu numa tentativa de resolver coordenada → endereço.
 *
 * Existe porque o frontend precisa distinguir TRÊS situações que, sem isso, chegariam todas
 * como "deu erro": achei o endereço, não existe endereço nesse ponto, e não consigo consultar
 * agora. A terceira é a que mais importa hoje — a `GOOGLE_MAPS_API_KEY` ainda não foi
 * configurada, então `UNAVAILABLE` é o caminho que roda na prática até a chave existir.
 *
 * Nenhum deles é HTTP 5xx. Coordenada no meio do oceano não tem endereço e isso é uma
 * RESPOSTA, não uma falha; e provedor indisponível é um fato operacional que o frontend
 * resolve mostrando a coordenada, não uma tela de erro.
 */
enum AddressLookupStatus: string
{
    /** Endereço resolvido. `address` vem preenchido. */
    case OK = 'ok';

    /** O provedor respondeu, mas não há endereço para aquele ponto. */
    case NOT_FOUND = 'not_found';

    /**
     * Não foi possível consultar: chave ausente, provedor fora do ar ou timeout. O frontend
     * deve cair no fallback de mostrar a coordenada e permitir digitar o endereço à mão.
     */
    case UNAVAILABLE = 'unavailable';

    public function isResolved(): bool
    {
        return $this === self::OK;
    }
}
