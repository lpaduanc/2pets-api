<?php

namespace Tests\Feature\Fiscal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Certificado A1 nunca aparece em resposta de API — critério de aceite do
 * docs/gap-simplesvet/specs/05-emissao-fiscal-nfe-nfce-nfse-spec.md
 * (`CertificateSecrecyTest`). O upload em si é fora de escopo deste agente (cofre externo,
 * `security-specialist`); este teste prova a metade que já existe: a LEITURA nunca vaza.
 */
class CertificateSecrecyTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_the_fiscal_settings_response_never_exposes_the_certificate_reference(): void
    {
        $this->clinic->update(['certificate_ref' => 'vault://acme-certificates/clinic-42.pfx']);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/fiscal-settings')
            ->assertOk();

        $response->assertJsonMissingPath('data.certificate_ref');
        $this->assertStringNotContainsString('vault://', $response->getContent());
        $this->assertTrue($response->json('data.has_certificate'));
    }

    public function test_the_settings_update_endpoint_cannot_write_the_certificate_reference(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/professional/fiscal-settings', [
                'tax_regime' => 'simples_nacional',
                'certificate_ref' => 'attacker-controlled-value',
            ])->assertOk();

        $this->assertNull($this->clinic->fresh()->certificate_ref);
    }
}
