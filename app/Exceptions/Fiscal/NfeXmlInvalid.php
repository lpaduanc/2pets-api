<?php

namespace App\Exceptions\Fiscal;

use RuntimeException;

/**
 * O XML montado do documento não passa nas regras locais (dado obrigatório faltando, XSD oficial,
 * ICMS-ST não suportado). É rejeição de NEGÓCIO antes de ir à SEFAZ: o documento vira `rejected`
 * com o motivo, sem consumir número — nunca `FiscalProviderUnavailable` (não adianta retentar).
 */
final class NfeXmlInvalid extends RuntimeException
{
    /** @param  list<string>  $errors */
    public static function because(array $errors): self
    {
        return new self('Validação local do XML: '.implode(' | ', array_slice(array_unique($errors), 0, 5)));
    }
}
