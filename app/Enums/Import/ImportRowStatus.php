<?php

namespace App\Enums\Import;

/** Estado de uma linha dentro de uma importação (item 26 do backlog gap-simplesvet). */
enum ImportRowStatus: string
{
    case PENDING = 'pending';
    case VALID = 'valid';
    case INVALID = 'invalid';
    case IMPORTED = 'imported';
    case SKIPPED = 'skipped';
    case DUPLICATE = 'duplicate';
}
