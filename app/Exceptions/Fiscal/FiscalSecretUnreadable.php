<?php

namespace App\Exceptions\Fiscal;

use RuntimeException;

/**
 * Segredo fiscal (A1, senha, CSC) que não decifra: chave `FISCAL_SECRETS_KEY` trocada, blob
 * adulterado ou copiado de outra organização. A mensagem nunca carrega o conteúdo.
 */
final class FiscalSecretUnreadable extends RuntimeException {}
