<?php

namespace App\Services\Fiscal\Nfse;

use App\Exceptions\Fiscal\NfeXmlInvalid;
use NFePHP\Common\Certificate;
use NFePHP\Common\Signer;

/**
 * Assinatura XMLDSig da DPS (`infDPS`, dentro de `DPS`) e do pedido de evento (`infPedReg`, dentro
 * de `pedRegEvento`) — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfse-nacional.md
 * §1.1-2. Enveloped + C14N (sem comentários) + só o certificado final, com o `Signer` do
 * sped-common (o mesmo da NF-e). O algoritmo é configurável porque o Anexo I não o fixa.
 */
final class NfseXmlSigner
{
    private const ALGORITHMS = ['sha1' => OPENSSL_ALGO_SHA1, 'sha256' => OPENSSL_ALGO_SHA256];

    public function signDps(Certificate $certificate, string $xml): string
    {
        return $this->sign($certificate, $xml, 'infDPS', 'DPS');
    }

    public function signEventRequest(Certificate $certificate, string $xml): string
    {
        return $this->sign($certificate, $xml, 'infPedReg', 'pedRegEvento');
    }

    private function sign(Certificate $certificate, string $xml, string $tag, string $root): string
    {
        $algorithm = self::ALGORITHMS[(string) config('fiscal.nfse.signature_algorithm')]
            ?? throw NfeXmlInvalid::because(['Algoritmo de assinatura da NFS-e inválido na configuração (sha1 ou sha256).']);

        return Signer::sign($certificate, $xml, $tag, 'Id', $algorithm, Signer::CANONICAL, $root);
    }
}
