<?php

namespace App\Services\Fiscal\Sefaz;

use NFePHP\Common\Certificate;
use NFePHP\Common\Soap\SoapInterface;

/**
 * Transporte SOAP com mTLS até a SEFAZ — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §7. Contrato para os testes trocarem por um dublê: a
 * suíte NUNCA fala com a SEFAZ real.
 */
interface SefazTransportFactory
{
    public function make(Certificate $certificate): SoapInterface;
}
