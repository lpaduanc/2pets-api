<?php

namespace App\Services\Fiscal\Sefaz;

use Illuminate\Support\Facades\File;
use NFePHP\Common\Certificate;
use NFePHP\Common\Soap\SoapInterface;

/**
 * Transporte real até a SEFAZ — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md
 * §3.5. `VerifyingSoapCurl` no lugar do `SoapCurl` do sped-common: o servidor é SEMPRE verificado
 * (sistema + ICP-Brasil, `SefazServerTrust`).
 *
 * O cURL precisa da chave privada em ARQUIVO durante o envio. Mitigações: (1) a chave vai cifrada
 * com senha aleatória de uso único (`setEncriptPrivateKey`); (2) a pasta é privada da aplicação
 * (`fiscal.sefaz.temporary_path`, 0700), não o /tmp compartilhado; (3) o sped-common apaga os
 * arquivos no destrutor e varre os esquecidos há mais de 45 min.
 */
final class CurlSefazTransportFactory implements SefazTransportFactory
{
    private const PRIVATE_FOLDER_MODE = 0700;

    public function __construct(private readonly SefazServerTrust $trust) {}

    public function make(Certificate $certificate): SoapInterface
    {
        $folder = (string) config('fiscal.sefaz.temporary_path');
        File::ensureDirectoryExists($folder, self::PRIVATE_FOLDER_MODE);

        $soap = new VerifyingSoapCurl($certificate, $this->trust);
        $soap->timeout((int) config('fiscal.sefaz.timeout_seconds'));
        $soap->setEncriptPrivateKey(true);
        $soap->setTemporaryFolder($folder);

        return $soap;
    }
}
