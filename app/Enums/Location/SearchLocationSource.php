<?php

namespace App\Enums\Location;

/** De onde veio a localização que o tutor confirmou para buscar. */
enum SearchLocationSource: string
{
    /** Posição do aparelho, confirmada no diálogo de localização. */
    case GPS = 'gps';

    /** CEP informado à mão (fallback quando o aparelho não dá a posição). */
    case ZIP_CODE = 'zip_code';

    /**
     * Ponto escolhido no seletor de mapa: arrastando o mapa (pino fixo no centro) ou por um
     * endereço do autocomplete (`GET /api/public/address-search`). Cobre o caso em que a
     * posição do aparelho está errada (VPN) ou não é onde o pet está (viagem).
     */
    case MAP = 'map';

    /** Endereço do cadastro do tutor (`user.address.latitude/longitude` do `GET /api/user`). */
    case PROFILE = 'profile';
}
