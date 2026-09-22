<?php

namespace App\Contracts\Import;

use App\Models\DataImport;

/**
 * Validador de uma linha de importação (item 26 do backlog gap-simplesvet) — um por entidade
 * suportada (`ClientImportValidator`, `PetImportValidator`...), resolvido por
 * `App\Services\Import\ImportEntityHandlerRegistry`. Regra 3 da spec: linha inválida nunca
 * trava o lote, o validador só reporta o resultado.
 */
interface ImportRowValidator
{
    /**
     * @param  array<string, string>  $row  já remapeado para nome de campo canônico
     * @return array{valid: bool, normalized: array<string, mixed>, errors: list<string>}
     */
    public function validate(array $row, DataImport $import): array;
}
