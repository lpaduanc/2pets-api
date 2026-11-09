<?php

namespace App\Services\Fiscal\Nfse\Transport;

use App\Exceptions\Fiscal\FiscalProviderUnavailable;

/**
 * Verificação do certificado do SERVIDOR do Sistema Nacional — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §10. Nunca desligada (diferente do SoapCurl do
 * sped-common usado na fase A). Os hosts usam cadeia pública (GlobalSign Root R46 / ISRG Root X1,
 * medido em 2026-11-08), então o trust store do sistema basta; `fiscal.nfse.ca_bundle` fixa um
 * bundle próprio. Bundle configurado e ilegível = falha FECHADA: nada sai.
 */
final class NfseServerTrust
{
    public const MISSING_CA_BUNDLE = 'nfse_ca_bundle';

    /**
     * Opção `verify` do Guzzle: `true` (trust store do sistema) ou o caminho do bundle.
     *
     * @throws FiscalProviderUnavailable
     */
    public function verifyOption(): string|bool
    {
        $bundle = $this->configuredBundle();

        if ($bundle === null) {
            return true;
        }

        if (! is_file($bundle) || ! is_readable($bundle)) {
            throw new FiscalProviderUnavailable('Bundle de CAs da NFS-e (fiscal.nfse.ca_bundle) não encontrado: nada foi enviado.');
        }

        return $bundle;
    }

    /** Código de prontidão quando o bundle configurado não existe. */
    public function problem(): ?string
    {
        $bundle = $this->configuredBundle();

        return $bundle !== null && ! is_readable($bundle) ? self::MISSING_CA_BUNDLE : null;
    }

    private function configuredBundle(): ?string
    {
        $bundle = trim((string) config('fiscal.nfse.ca_bundle'));

        return $bundle === '' ? null : $bundle;
    }
}
