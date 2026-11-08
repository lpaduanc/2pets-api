<?php

namespace App\Exceptions\Fiscal;

use RuntimeException;

/**
 * Falha de TRANSPORTE com o provedor/SEFAZ (timeout, fora do ar, certificado inacessível) — spec
 * docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §5. Não é rejeição: rejeição de negócio volta
 * como `FiscalProviderResult` com status `REJECTED`. Quem emite captura esta exceção, mantém o
 * documento `pending` e agenda nova tentativa — a venda nunca fica travada por ela.
 */
final class FiscalProviderUnavailable extends RuntimeException {}
