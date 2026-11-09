<?php

namespace Tests\Feature\Fiscal;

use App\Contracts\FiscalGatewayResolver;
use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\Enums\FiscalDocumentKind;
use App\Enums\TaxRegime;
use App\Models\FiscalDocument;
use App\Services\Fiscal\LogFiscalProviderGateway;
use App\Services\Fiscal\Nfse\NfseNacionalGateway;
use App\Services\Fiscal\Sefaz\SefazFiscalGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Fiscal\Concerns\BuildsNfseScenario;
use Tests\TestCase;

/**
 * Escolha do driver da NFS-e e bloco `transmission.nfse` do `fiscal-settings` — spec
 * docs/gap-petflow/specs/fase4-transmissao-propria-nfse-nacional.md §3/§4: Sistema Nacional só
 * com `fiscal.nfse.enabled` + A1 válido; NF-e/NFC-e seguem a regra da fase A, sem mudança.
 */
class NfseTransmissionSelectionTest extends TestCase
{
    use BuildsNfseScenario;
    use RefreshDatabase;

    public function test_enabled_with_a_valid_certificate_sends_nfse_to_the_national_system(): void
    {
        $this->buildNfseClinic();

        $this->assertDriver(NfseNacionalGateway::class, FiscalDocumentKind::NFSE);
        $this->assertDriver(SefazFiscalGateway::class, FiscalDocumentKind::NFE);
        $this->settings()
            ->assertJsonPath('data.transmission.nfse', ['mode' => 'national', 'ready' => true, 'missing' => []])
            ->assertJsonPath('data.transmission.documents', ['nfe' => 'sefaz', 'nfce' => 'simulated'])
            ->assertJsonPath('data.fiscal_series.nfse', ['series' => '1', 'next_number' => 1, 'start_number' => 1]);
    }

    public function test_the_nfse_switch_is_independent_from_the_sefaz_switch(): void
    {
        $this->buildNfseClinic();
        config(['fiscal.nfse.enabled' => false]);

        $this->assertDriver(LogFiscalProviderGateway::class, FiscalDocumentKind::NFSE);
        $this->assertDriver(SefazFiscalGateway::class, FiscalDocumentKind::NFE);
        $this->settings()->assertJsonPath('data.transmission.nfse.mode', 'simulated')->assertJsonPath('data.transmission.nfse.missing', ['nfse_disabled']);
    }

    public function test_without_certificate_the_nfse_stays_simulated_and_does_not_reserve_dps_numbers(): void
    {
        $this->buildNfseClinic(withCertificate: false);

        $document = $this->issueSale($this->paidServiceSale([[$this->makeTaxedService(), 1]]))->json('data.0');

        $this->assertSame(['log', 'authorized', null], [$document['provider'], $document['status'], $document['dps_number']]);
        $this->assertSame([], $this->sefin->requests);
        $this->settings()->assertJsonPath('data.transmission.nfse.missing', ['certificate']);
    }

    public function test_regime_data_needed_by_the_dps_is_listed_without_hiding_the_national_mode(): void
    {
        $this->buildNfseClinic();
        $this->clinic->forceFill(['effective_sales_tax_rate' => null])->save();
        $this->settings()->assertJsonPath('data.transmission.nfse.mode', 'national')->assertJsonPath('data.transmission.nfse.missing', ['simples_effective_rate']);

        $this->clinic->forceFill(['tax_regime' => TaxRegime::LUCRO_PRESUMIDO->value, 'iss_rate' => null])->save();
        $this->settings()->assertJsonPath('data.transmission.nfse.missing', ['iss_rate']);
    }

    public function test_a_configured_but_missing_ca_bundle_is_flagged(): void
    {
        $this->buildNfseClinic();
        config(['fiscal.nfse.ca_bundle' => '/nao/existe/cadeia.pem']);

        $this->settings()->assertJsonPath('data.transmission.nfse.missing', ['nfse_ca_bundle']);
    }

    public function test_state_registration_is_not_required_for_nfse(): void
    {
        $this->buildNfseClinic();
        $this->clinic->forceFill(['state_registration' => null])->save();

        $this->settings()->assertJsonPath('data.transmission.nfse.missing', [])->assertJsonPath('data.transmission.missing', ['nfce_csc', 'state_registration']);
    }

    public function test_professional_without_organization_and_existing_documents_keep_their_driver(): void
    {
        $this->buildNfseClinic();
        $resolver = app(FiscalGatewayResolver::class);

        $this->assertInstanceOf(LogFiscalProviderGateway::class, $resolver->forOwner(new FiscalOwner(null, $this->owner->id), FiscalDocumentKind::NFSE));
        $this->assertInstanceOf(NfseNacionalGateway::class, $resolver->forDocument(new FiscalDocument(['provider' => NfseNacionalGateway::NAME])));
        $this->assertInstanceOf(LogFiscalProviderGateway::class, $resolver->forDocument(new FiscalDocument(['provider' => 'log', 'kind' => 'nfse'])));
    }

    public function test_the_dps_series_can_be_configured_and_zero_is_refused(): void
    {
        $this->buildNfseClinic();

        $this->actingAs($this->owner, 'sanctum')->putJson('/api/professional/fiscal-settings', ['fiscal_series' => ['nfse' => ['series' => '2', 'next_number' => 10]]])
            ->assertOk()
            ->assertJsonPath('data.fiscal_series.nfse', ['series' => '2', 'next_number' => 10, 'start_number' => 10]);
        $this->putJson('/api/professional/fiscal-settings', ['fiscal_series' => ['nfse' => ['series' => '0']]])->assertStatus(422);

        $document = $this->issueSale($this->paidServiceSale([[$this->makeTaxedService(), 1]]))->json('data.0');
        $this->assertSame(['2', 10], [$document['dps_series'], $document['dps_number']]);
        $this->assertStringContainsString('<serie>00002</serie><nDPS>10</nDPS>', $this->sefin->xmlFor('emission')[0]);
    }

    public function test_the_national_driver_refuses_number_invalidation_instead_of_throwing(): void
    {
        $this->buildNfseClinic();
        $gateway = app(NfseNacionalGateway::class);

        $this->assertFalse($gateway->capabilities()->invalidation);
        $this->assertSame('rejected', $gateway->invalidate(new \App\Models\FiscalNumberInvalidation)->status->value);
    }

    private function assertDriver(string $expected, FiscalDocumentKind $kind): void
    {
        $this->assertInstanceOf($expected, app(FiscalGatewayResolver::class)->forOwner(FiscalOwner::forOrganization($this->clinic->id), $kind));
    }

    private function settings(): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/fiscal-settings')->assertOk();
    }
}
