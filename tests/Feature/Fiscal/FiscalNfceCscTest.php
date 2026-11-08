<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalEnvironment;
use App\Models\FiscalNfceCsc;
use App\Services\Fiscal\Certificate\FiscalNfceCscService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fiscal\Concerns\BuildsSefazScenario;
use Tests\TestCase;

/**
 * CSC da NFC-e por ambiente — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md
 * §4: token cifrado, nunca devolvido, só o dono.
 */
class FiscalNfceCscTest extends TestCase
{
    use BuildsSefazScenario;
    use RefreshDatabase;

    private const URL = '/api/professional/fiscal-settings/nfce-csc';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSefazClinic(withCertificate: false, withCsc: false);
    }

    public function test_the_owner_saves_a_csc_per_environment_and_the_token_never_comes_back(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->putJson(self::URL, ['environment' => 'homologation', 'csc_id' => '000001', 'csc_token' => self::CSC_TOKEN])
            ->assertOk()
            ->assertJsonPath('data.homologation.csc_id', '000001')
            ->assertJsonPath('data.homologation.configured', true)
            ->assertJsonPath('data.production', null);

        $this->assertStringNotContainsString(self::CSC_TOKEN, $response->getContent());
        $stored = FiscalNfceCsc::query()->firstOrFail();
        $this->assertStringNotContainsString(self::CSC_TOKEN, $stored->token_ciphertext);
        $this->assertSame(['id' => '000001', 'token' => self::CSC_TOKEN], app(FiscalNfceCscService::class)->credentials($this->clinic->id, FiscalEnvironment::HOMOLOGATION));

        $settings = $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/fiscal-settings')->assertOk();
        $settings->assertJsonPath('data.nfce_csc.homologation.csc_id', '000001');
        $this->assertStringNotContainsString(self::CSC_TOKEN, $settings->getContent());
    }

    public function test_saving_again_replaces_the_token_and_deleting_removes_it(): void
    {
        $this->actingAs($this->owner, 'sanctum')->putJson(self::URL, ['environment' => 'production', 'csc_id' => '2', 'csc_token' => self::CSC_TOKEN])->assertOk();
        $this->actingAs($this->owner, 'sanctum')->putJson(self::URL, ['environment' => 'production', 'csc_id' => '3', 'csc_token' => 'ZZZZ1111YYYY2222XXXX'])->assertOk();

        $this->assertDatabaseCount('fiscal_nfce_cscs', 1);
        $this->assertSame('ZZZZ1111YYYY2222XXXX', app(FiscalNfceCscService::class)->credentials($this->clinic->id, FiscalEnvironment::PRODUCTION)['token']);

        $this->actingAs($this->owner, 'sanctum')->deleteJson(self::URL.'/production')->assertOk()->assertJsonPath('data.production', null);
        $this->assertDatabaseCount('fiscal_nfce_cscs', 0);
        $this->actingAs($this->owner, 'sanctum')->deleteJson(self::URL.'/production')->assertNotFound();
    }

    public function test_invalid_identifiers_and_tokens_are_refused(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson(self::URL, ['environment' => 'staging', 'csc_id' => '1234567', 'csc_token' => 'curto'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['environment', 'csc_id', 'csc_token']);
    }

    public function test_only_the_owner_manages_the_csc(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->putJson(self::URL, ['environment' => 'homologation', 'csc_id' => '1', 'csc_token' => self::CSC_TOKEN])
            ->assertForbidden();

        $this->assertDatabaseCount('fiscal_nfce_cscs', 0);
    }
}
