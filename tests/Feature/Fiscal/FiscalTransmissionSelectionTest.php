<?php

namespace Tests\Feature\Fiscal;

use App\Contracts\FiscalGatewayResolver;
use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\Enums\FiscalDocumentKind;
use App\Models\FiscalDocument;
use App\Services\Fiscal\LogFiscalProviderGateway;
use App\Services\Fiscal\Sefaz\SefazFiscalGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fiscal\Concerns\BuildsSefazScenario;
use Tests\TestCase;

/**
 * Escolha do driver por dono e bloco `transmission` do `fiscal-settings` — spec docs/gap-petflow/
 * specs/fase4-transmissao-propria-nfe-nfce.md §6: SEFAZ só com configuração ligada + A1 válido
 * (+ CSC na NFC-e); documento existente nunca troca de driver.
 */
class FiscalTransmissionSelectionTest extends TestCase
{
    use BuildsSefazScenario;
    use RefreshDatabase;

    public function test_without_certificate_everything_is_simulated_and_the_settings_say_what_is_missing(): void
    {
        $this->buildSefazClinic(withCertificate: false, withCsc: false);

        $this->assertDriver(LogFiscalProviderGateway::class, FiscalDocumentKind::NFE);
        $this->settings()
            ->assertJsonPath('data.transmission.mode', 'simulated')
            ->assertJsonPath('data.transmission.ready', false)
            ->assertJsonPath('data.transmission.missing', ['certificate', 'nfce_csc'])
            ->assertJsonPath('data.transmission.environment', 'homologation')
            ->assertJsonPath('data.resolved_city_ibge_code', '3550308');
    }

    public function test_a_valid_certificate_sends_nfe_to_sefaz_and_nfce_waits_for_the_csc(): void
    {
        $this->buildSefazClinic(withCsc: false);

        $this->assertDriver(SefazFiscalGateway::class, FiscalDocumentKind::NFE);
        $this->assertDriver(LogFiscalProviderGateway::class, FiscalDocumentKind::NFCE);
        $this->settings()
            ->assertJsonPath('data.transmission.mode', 'sefaz')
            ->assertJsonPath('data.transmission.documents', ['nfe' => 'sefaz', 'nfce' => 'simulated'])
            ->assertJsonPath('data.transmission.missing', ['nfce_csc'])
            ->assertJsonPath('data.provider.name', 'sefaz');
    }

    public function test_certificate_csc_and_complete_emitter_make_the_organization_ready(): void
    {
        $this->buildSefazClinic();

        $this->assertDriver(SefazFiscalGateway::class, FiscalDocumentKind::NFCE);
        $this->settings()->assertJsonPath('data.transmission.ready', true)->assertJsonPath('data.transmission.missing', []);
    }

    public function test_the_configuration_switch_turns_everything_back_to_simulated(): void
    {
        $this->buildSefazClinic();
        config(['fiscal.sefaz.enabled' => false]);

        $this->assertDriver(LogFiscalProviderGateway::class, FiscalDocumentKind::NFCE);
        $this->settings()->assertJsonPath('data.transmission.mode', 'simulated')->assertJsonPath('data.transmission.missing', ['sefaz_disabled']);
    }

    public function test_an_expired_certificate_falls_back_to_simulated_and_is_flagged(): void
    {
        $this->buildSefazClinic();
        $this->travel(400)->days();

        $this->assertDriver(LogFiscalProviderGateway::class, FiscalDocumentKind::NFE);
        $this->settings()->assertJsonPath('data.transmission.missing', ['certificate_expired'])->assertJsonPath('data.certificate.is_expired', true);
    }

    public function test_incomplete_emitter_data_is_listed_without_hiding_the_sefaz_mode(): void
    {
        $this->buildSefazClinic();
        $this->clinic->forceFill(['state_registration' => null, 'city' => 'Cidade Inexistente'])->save();

        $this->assertDriver(SefazFiscalGateway::class, FiscalDocumentKind::NFE);
        $this->settings()->assertJsonPath('data.transmission.missing', ['state_registration', 'city_ibge_code']);

        $this->actingAs($this->owner, 'sanctum')->putJson('/api/professional/fiscal-settings', ['city_ibge_code' => '3550308'])->assertOk();
        $this->settings()->assertJsonPath('data.transmission.missing', ['state_registration'])->assertJsonPath('data.city_ibge_code', '3550308');
    }

    public function test_a_professional_without_organization_stays_simulated(): void
    {
        $this->buildSefazClinic();

        $gateway = app(FiscalGatewayResolver::class)->forOwner(new FiscalOwner(null, $this->owner->id), FiscalDocumentKind::NFE);

        $this->assertInstanceOf(LogFiscalProviderGateway::class, $gateway);
    }

    public function test_an_existing_document_keeps_the_driver_it_was_created_with(): void
    {
        $this->buildSefazClinic(withCertificate: false);
        $resolver = app(FiscalGatewayResolver::class);

        $this->assertInstanceOf(SefazFiscalGateway::class, $resolver->forDocument(new FiscalDocument(['provider' => 'sefaz'])));
        $this->assertInstanceOf(LogFiscalProviderGateway::class, $resolver->forDocument(new FiscalDocument(['provider' => 'log'])));
    }

    private function assertDriver(string $expected, FiscalDocumentKind $kind): void
    {
        $this->assertInstanceOf($expected, app(FiscalGatewayResolver::class)->forOwner(FiscalOwner::forOrganization($this->clinic->id), $kind));
    }

    private function settings(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/fiscal-settings')->assertOk();
    }
}
