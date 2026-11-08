<?php

namespace App\Services\Fiscal\Sefaz;

use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalEnvironment;
use App\Exceptions\Fiscal\FiscalCertificateUnavailable;
use App\Exceptions\Fiscal\FiscalProviderUnavailable;
use App\Models\Organization;
use App\Services\Fiscal\Certificate\FiscalCertificateVault;
use App\Services\Fiscal\Certificate\FiscalNfceCscService;
use NFePHP\NFe\Tools;

/**
 * Monta o `Tools` do sped-nfe para UMA organização, modelo e ambiente — spec docs/gap-petflow/
 * specs/fase4-transmissao-propria-nfe-nfce.md §7.1. As URLs dos webservices vêm do próprio
 * sped-nfe (`storage/wsnfe_4.00_mod55|65.xml`, por UF e ambiente, incluindo as UFs que usam
 * SVRS/SVAN). Sem certificado ou sem CSC não há como assinar: `FiscalProviderUnavailable` — o
 * documento fica pendente e a reconciliação tenta de novo quando o dono cadastrar.
 */
final class SefazToolsFactory
{
    private const LAYOUT_VERSION = '4.00';

    private const MODELS = [FiscalDocumentKind::NFE->value => 55, FiscalDocumentKind::NFCE->value => 65];

    public function __construct(
        private readonly FiscalCertificateVault $vault,
        private readonly FiscalNfceCscService $cscs,
        private readonly SefazTransportFactory $transports,
    ) {}

    /** @throws FiscalProviderUnavailable */
    public function make(int $organizationId, FiscalDocumentKind $kind, FiscalEnvironment $environment): Tools
    {
        abort_unless(isset(self::MODELS[$kind->value]), 422, 'NFS-e não é transmitida pela SEFAZ (fase B: Emissor Nacional).');
        $this->assertEnabled();
        $organization = Organization::findOrFail($organizationId);

        try {
            $certificate = $this->vault->open($organizationId);
        } catch (FiscalCertificateUnavailable $unavailable) {
            throw new FiscalProviderUnavailable($unavailable->getMessage());
        }

        $tools = new Tools($this->config($organization, $kind, $environment), $certificate);
        $tools->model(self::MODELS[$kind->value]);
        $tools->setVerAplic((string) config('fiscal.sefaz.app_version'));
        $tools->loadSoapClass($this->transports->make($certificate));

        return $tools;
    }

    private function assertEnabled(): void
    {
        if (! config('fiscal.sefaz.enabled')) {
            throw new FiscalProviderUnavailable('Transmissão à SEFAZ desligada na configuração (fiscal.sefaz.enabled).');
        }
    }

    private function config(Organization $organization, FiscalDocumentKind $kind, FiscalEnvironment $environment): string
    {
        return json_encode([
            'atualizacao' => now()->format('Y-m-d H:i:s'),
            'tpAmb' => SefazCodes::environment($environment),
            'razaosocial' => (string) $organization->business_name,
            'cnpj' => (string) $organization->cnpj,
            'siglaUF' => strtoupper((string) $organization->state),
            'schemes' => (string) config('fiscal.sefaz.schema'),
            'versao' => self::LAYOUT_VERSION,
        ] + $this->csc($organization->id, $kind, $environment), JSON_THROW_ON_ERROR);
    }

    /** @return array{CSC?: string, CSCid?: string} */
    private function csc(int $organizationId, FiscalDocumentKind $kind, FiscalEnvironment $environment): array
    {
        if ($kind !== FiscalDocumentKind::NFCE) {
            return [];
        }

        $credentials = $this->cscs->credentials($organizationId, $environment);

        if ($credentials === null) {
            throw new FiscalProviderUnavailable('Cadastre o CSC da NFC-e deste ambiente para gerar o QR Code.');
        }

        return ['CSC' => $credentials['token'], 'CSCid' => str_pad($credentials['id'], 6, '0', STR_PAD_LEFT)];
    }
}
