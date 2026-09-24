<?php

namespace App\Enums\Location;

/** De onde veio a localização que o tutor confirmou para buscar. */
enum SearchLocationSource: string
{
    /** Posição do aparelho, confirmada no diálogo de localização. */
    case GPS = 'gps';

    /** CEP informado à mão (fallback quando o aparelho não dá a posição). */
    case ZIP_CODE = 'zip_code';
}
