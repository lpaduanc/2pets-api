<?php

namespace App\Enums\Import;

/** Ciclo de vida de uma importação (item 26 do backlog gap-simplesvet). */
enum ImportStatus: string
{
    case UPLOADED = 'uploaded';
    case MAPPING = 'mapping';
    case VALIDATING = 'validating';
    case READY = 'ready';
    case IMPORTING = 'importing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case ROLLED_BACK = 'rolled_back';

    public function label(): string
    {
        return match ($this) {
            self::UPLOADED => 'Enviado',
            self::MAPPING => 'Mapeando colunas',
            self::VALIDATING => 'Validando',
            self::READY => 'Pronto para importar',
            self::IMPORTING => 'Importando',
            self::COMPLETED => 'Concluído',
            self::FAILED => 'Falhou',
            self::ROLLED_BACK => 'Desfeito',
        };
    }
}
