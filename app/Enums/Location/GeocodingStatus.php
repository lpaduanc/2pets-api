<?php

namespace App\Enums\Location;

/**
 * Situação do ponto geográfico do endereço de um usuário (`users.geocoding_status`).
 *
 * `null` na coluna significa "nunca tentou" — conta sem endereço, ou legado anterior a esta
 * coluna sem coordenada. `FAILED` é o estado que importa: o endereço está salvo, mas o
 * profissional está FORA da busca por proximidade até o reprocessamento
 * (`GeocodeUserAddress` / `geocoding:retry-failed`) resolver.
 */
enum GeocodingStatus: string
{
    /** Coordenada gravada a partir do endereço atual (ou informada pelo cliente no cadastro). */
    case RESOLVED = 'resolved';

    /** Endereço salvo sem coordenada: geocoding falhou ou provedor indisponível. */
    case FAILED = 'failed';
}
