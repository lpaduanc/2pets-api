<?php

namespace App\Services\Fiscal\Nfse\Transport;

use NFePHP\Common\Certificate;

/**
 * HTTPS com mTLS até a Sefin Nacional — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §6/§10. Contrato para os testes trocarem por um
 * dublê: a suíte NUNCA fala com a API real. Qualquer falha de rede/TLS sobe como exceção; quem
 * traduz para `FiscalProviderUnavailable` é o `NfseNacionalClient`.
 */
interface NfseNacionalTransport
{
    public function send(NfseHttpExchange $request, Certificate $certificate): NfseHttpExchange;
}
