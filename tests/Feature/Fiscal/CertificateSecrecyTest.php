<?php

namespace Tests\Feature\Fiscal;

use App\Services\Fiscal\Certificate\FiscalCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fiscal\Concerns\BuildsSefazScenario;
use Tests\Support\Fiscal\TestCertificate;
use Tests\TestCase;

/**
 * Certificado A1 nunca aparece em resposta de API — critério de aceite do
 * docs/gap-simplesvet/specs/05-emissao-fiscal-nfe-nfce-nfse-spec.md
 * (`CertificateSecrecyTest`). Com o upload próprio (spec fase4-transmissao-propria-nfe-nfce §3),
 * também prova que um A1 REAL cadastrado não vaza por nenhuma leitura: nem bytes, nem senha, nem
 * texto cifrado, nem a referência interna.
 */
class CertificateSecrecyTest extends TestCase
{
    use BuildsSefazScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_the_fiscal_settings_response_never_exposes_the_certificate_reference(): void
    {
        // `certificate_ref` fica fora do `$fillable` de propósito (só o fluxo do cofre grava).
        $this->clinic->forceFill(['certificate_ref' => 'vault://acme-certificates/clinic-42.pfx'])->save();

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

    public function test_an_uploaded_certificate_never_leaks_through_the_settings_or_the_documents(): void
    {
        $this->completeEmitter();
        $pfx = TestCertificate::pfx(self::CLINIC_CNPJ);
        app(FiscalCertificateService::class)->upload($this->clinic->fresh(), $pfx, TestCertificate::PASSWORD, $this->owner);

        $responses = [
            $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/fiscal-settings')->assertOk(),
            $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/fiscal-settings/certificate')->assertOk(),
            $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/fiscal-documents')->assertOk(),
        ];

        $responses[0]->assertJsonPath('data.has_certificate', true)->assertJsonPath('data.certificate.subject_document', self::CLINIC_CNPJ);
        foreach ($responses as $response) {
            foreach ([TestCertificate::PASSWORD, base64_encode($pfx), 'ciphertext', 'fiscal_certificates:'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
    }

    public function test_the_settings_update_endpoint_cannot_write_certificate_secrets(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/professional/fiscal-settings', ['pfx_ciphertext' => 'x', 'certificate_expires_at' => '2099-01-01'])
            ->assertOk();

        $this->assertDatabaseCount('fiscal_certificates', 0);
        $this->assertNull($this->clinic->fresh()->certificate_expires_at);
    }
}
