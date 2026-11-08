<?php

namespace App\Enums;

/**
 * Como o CNPJ do certificado A1 bate com o da organização — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §3.2. `ROOT` é a filial assinando com o e-CNPJ da matriz:
 * a SEFAZ aceita (regra 213 compara só o CNPJ-base, 8 dígitos).
 */
enum FiscalCertificateMatch: string
{
    case EXACT = 'exact';
    case ROOT = 'root';
}
