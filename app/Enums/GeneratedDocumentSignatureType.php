<?php

namespace App\Enums;

/**
 * Contrato docs/gap-simplesvet/specs/15-modelos-documento-receituario-assinatura-spec.md.
 * `A1_CERTIFICATE` nasce no enum sem driver — mesma cautela já aplicada a
 * `prescriptions.signature_type` (preparar o vocabulário, não a funcionalidade), para o dia
 * em que ICP-Brasil for priorizado sem `ALTER TYPE` numa tabela populada.
 */
enum GeneratedDocumentSignatureType: string
{
    case NONE = 'none';
    case IMAGE = 'image';
    case SIMPLE_ELECTRONIC = 'simple_electronic';
    case A1_CERTIFICATE = 'a1_certificate';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
