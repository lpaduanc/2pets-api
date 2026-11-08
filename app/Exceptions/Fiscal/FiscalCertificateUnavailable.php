<?php

namespace App\Exceptions\Fiscal;

use RuntimeException;

/**
 * A organização não tem A1 utilizável agora (não enviado, removido, vencido ou ilegível). O
 * driver da SEFAZ converte em `FiscalProviderUnavailable`: o documento fica pendente e volta a
 * ser tentado — quando o dono enviar o certificado, a reconciliação transmite.
 */
final class FiscalCertificateUnavailable extends RuntimeException {}
