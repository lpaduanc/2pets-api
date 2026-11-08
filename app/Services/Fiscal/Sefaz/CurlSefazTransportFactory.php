<?php

namespace App\Services\Fiscal\Sefaz;

use Illuminate\Support\Facades\File;
use NFePHP\Common\Certificate;
use NFePHP\Common\Soap\SoapCurl;
use NFePHP\Common\Soap\SoapInterface;

/**
 * cURL do sped-common — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md §3.5.
 *
 * O cURL precisa da chave privada em ARQUIVO durante o envio. Mitigações: (1) a chave vai cifrada
 * com senha aleatória de uso único (`setEncriptPrivateKey`); (2) a pasta é privada da aplicação
 * (`fiscal.sefaz.temporary_path`, 0700), não o /tmp compartilhado; (3) o sped-common apaga os
 * arquivos no destrutor e varre os esquecidos há mais de 45 min.
 */
final class CurlSefazTransportFactory implements SefazTransportFactory
{
    private const PRIVATE_FOLDER_MODE = 0700;

    public function make(Certificate $certificate): SoapInterface
    {
        $folder = (string) config('fiscal.sefaz.temporary_path');
        File::ensureDirectoryExists($folder, self::PRIVATE_FOLDER_MODE);

        $soap = new SoapCurl($certificate);
        $soap->timeout((int) config('fiscal.sefaz.timeout_seconds'));
        $soap->setEncriptPrivateKey(true);
        $soap->setTemporaryFolder($folder);

        return $soap;
    }
}
