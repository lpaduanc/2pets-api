<?php

namespace Tests\Feature\Fiscal\Concerns;

use App\Enums\FiscalEnvironment;
use App\Services\Fiscal\Certificate\FiscalCertificateService;
use App\Services\Fiscal\Certificate\FiscalNfceCscService;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Fiscal\FakeSefazTransport;
use Tests\Support\Fiscal\TestCertificate;

/**
 * Clínica pronta para a transmissão própria (spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md): emitente completo em São Paulo/SP, A1 de TESTE gerado na
 * hora, CSC de homologação, `fiscal.sefaz.enabled` ligado, disco fiscal falso e o transporte SOAP
 * trocado pelo dublê — nada sai para a SEFAZ.
 */
trait BuildsSefazScenario
{
    use BuildsFiscalScenarios;

    protected const CLINIC_CNPJ = '11222333000181';

    protected const CSC_TOKEN = 'A1B2C3D4E5F6G7H8I9J0K1L2M3N4O5P6';

    protected FakeSefazTransport $sefaz;

    protected function buildSefazClinic(bool $withCertificate = true, bool $withCsc = true): void
    {
        $this->buildClinic();
        Storage::fake('local');
        config(['fiscal.sefaz.enabled' => true]);
        $this->sefaz = FakeSefazTransport::bind($this->app);
        $this->completeEmitter();

        if ($withCertificate) {
            app(FiscalCertificateService::class)->upload($this->clinic->fresh(), TestCertificate::pfx(self::CLINIC_CNPJ), TestCertificate::PASSWORD, $this->owner);
        }

        if ($withCsc) {
            app(FiscalNfceCscService::class)->save($this->clinic->fresh(), FiscalEnvironment::HOMOLOGATION, '000001', self::CSC_TOKEN, $this->owner);
        }
    }

    protected function completeEmitter(): void
    {
        $this->clinic->forceFill([
            'cnpj' => self::CLINIC_CNPJ,
            'business_name' => 'Clinica Veterinaria Teste Ltda',
            'state_registration' => '110042490114',
            'address' => 'Rua Augusta',
            'number' => '100',
            'neighborhood' => 'Consolacao',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01305000',
            'fiscal_environment' => FiscalEnvironment::HOMOLOGATION->value,
        ])->save();
    }
}
