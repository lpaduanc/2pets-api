<?php

namespace Tests\Feature\Fiscal;

use App\Models\Organization;
use App\Models\TaxProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/** Cadastro de perfil tributário — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §2/§6. */
class TaxProfileCrudTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_owner_creates_a_profile_with_its_cfop_slots(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/tax-profiles', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Rações')
            ->assertJsonPath('data.icms_csosn', '102')
            ->assertJsonPath('data.icms_rate', '0.0000')
            ->assertJsonPath('data.origin', '0')
            ->assertJsonPath('data.is_default', false);

        $this->assertSame(['sale_consumer|intrastate|5102', 'return_from_customer|intrastate|1202'], array_map(
            fn (array $cfop): string => "{$cfop['operation']}|{$cfop['scope']}|{$cfop['cfop']}",
            $response->json('data.cfops'),
        ));
        $this->assertSame($this->clinic->id, TaxProfile::findOrFail($response->json('data.id'))->organization_id);
    }

    public function test_the_icms_code_required_follows_the_tax_regime(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/tax-profiles', $this->payload(['icms_csosn' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('icms_csosn');

        $this->clinic->forceFill(['tax_regime' => 'lucro_presumido'])->save();
        $this->postJson('/api/professional/tax-profiles', $this->payload())->assertStatus(422)->assertJsonValidationErrors('icms_cst');
        $this->postJson('/api/professional/tax-profiles', $this->payload(['icms_cst' => '00', 'icms_rate' => '18.1234']))->assertCreated();
        $this->postJson('/api/professional/tax-profiles', $this->payload(['icms_cst' => '00', 'icms_rate' => '18.12345']))->assertStatus(422)->assertJsonValidationErrors('icms_rate');
    }

    public function test_cfop_must_match_the_direction_and_slots_cannot_repeat(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/tax-profiles', $this->payload(['cfops' => [
            ['operation' => 'return_from_customer', 'scope' => 'intrastate', 'cfop' => '5202'],
            ['operation' => 'sale_consumer', 'scope' => 'interstate', 'cfop' => '5102'],
            ['operation' => 'sale_resale', 'scope' => 'intrastate', 'cfop' => '5102'],
            ['operation' => 'sale_resale', 'scope' => 'intrastate', 'cfop' => '5102'],
        ]]))->assertStatus(422)->assertJsonValidationErrors(['cfops.0.cfop', 'cfops.1.cfop', 'cfops.3.operation']);
    }

    public function test_marking_a_new_default_releases_the_previous_one(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/tax-profiles', $this->payload(['is_default' => true]))->assertCreated()->json('data.id');

        $this->assertFalse($this->defaultTaxProfile->fresh()->is_default);
        $this->assertTrue(TaxProfile::findOrFail($id)->is_default);

        $this->putJson("/api/professional/tax-profiles/{$this->defaultTaxProfile->id}", ['is_default' => true])->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertFalse(TaxProfile::findOrFail($id)->is_default);
    }

    public function test_update_replaces_the_cfop_table_only_when_sent(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/tax-profiles/{$this->defaultTaxProfile->id}", ['name' => 'Geral'])
            ->assertOk()
            ->assertJsonCount(8, 'data.cfops');

        $this->putJson("/api/professional/tax-profiles/{$this->defaultTaxProfile->id}", ['cfops' => [['operation' => 'sale_consumer', 'scope' => 'intrastate', 'cfop' => '5405']]])
            ->assertOk()
            ->assertJsonCount(1, 'data.cfops')
            ->assertJsonPath('data.cfops.0.cfop', '5405');
    }

    public function test_a_profile_in_use_cannot_be_deleted(): void
    {
        $profile = $this->makeTaxProfile(['name' => 'Em uso']);
        $this->makeProduct(['tax_profile_id' => $profile->id]);
        $unused = $this->makeTaxProfile(['name' => 'Sobrando']);

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/professional/tax-profiles/{$profile->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Perfil em uso: 1 produto(s) e 0 grupo(s). Troque o perfil deles antes de excluir.');

        $this->deleteJson("/api/professional/tax-profiles/{$unused->id}")->assertOk();
        $this->assertSoftDeleted('tax_profiles', ['id' => $unused->id]);
    }

    public function test_catalog_readers_list_profiles_but_only_fiscal_managers_write(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/tax-profiles', $this->payload())->assertForbidden();
        $this->actingAs($this->groomer, 'sanctum')->deleteJson("/api/professional/tax-profiles/{$this->defaultTaxProfile->id}")->assertForbidden();

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/tax-profiles')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->defaultTaxProfile->id)
            ->assertJsonPath('data.0.products_count', 0);
    }

    public function test_another_clinic_profile_is_invisible(): void
    {
        $other = Organization::factory()->create();
        $foreign = $this->makeTaxProfile(['name' => 'De outra clínica'], organization: $other);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/tax-profiles/{$foreign->id}")->assertNotFound();
        $this->putJson("/api/professional/tax-profiles/{$foreign->id}", ['name' => 'x'])->assertNotFound();
        $this->assertNotContains($foreign->id, array_column($this->getJson('/api/professional/tax-profiles')->json('data'), 'id'));
    }

    public function test_templates_are_read_only_presets_with_the_accountant_disclaimer(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/tax-profiles/templates')->assertOk();

        $this->assertStringContainsString('contador', $response->json('disclaimer'));
        $this->assertSame('102', $response->json('data.0.values.icms_csosn'));
        $this->assertNull($response->json('data.0.values.icms_rate'));
        $this->assertSame(1, TaxProfile::count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Rações',
            'origin' => '0',
            'icms_csosn' => '102',
            'pis_cst' => '49',
            'cofins_cst' => '49',
            'cfops' => [
                ['operation' => 'sale_consumer', 'scope' => 'intrastate', 'cfop' => '5102'],
                ['operation' => 'return_from_customer', 'scope' => 'intrastate', 'cfop' => '1202'],
            ],
        ];
    }
}
