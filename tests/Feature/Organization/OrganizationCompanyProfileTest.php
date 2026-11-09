<?php

namespace Tests\Feature\Organization;

use App\Enums\FiscalCertificateMatch;
use App\Enums\FiscalDocumentStatus;
use App\Models\FiscalCertificate;
use App\Models\FiscalDocument;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Fiscal\Concerns\BuildsSefazScenario;
use Tests\TestCase;

/**
 * `professional/organization` — o dono edita razão social, CNPJ e endereço do emitente, que a
 * transmissão própria cobra em `transmission.missing` (spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §6) e até aqui só o cadastro gravava.
 */
class OrganizationCompanyProfileTest extends TestCase
{
    use BuildsSefazScenario;
    use RefreshDatabase;

    private const URL = '/api/professional/organization';

    /** CNPJ válido de outra raiz (11444777…), para as travas de certificado e de unicidade. */
    private const OTHER_ROOT_CNPJ = '11444777000161';

    /** Filial da clínica: mesma raiz de `CLINIC_CNPJ`. */
    private const BRANCH_CNPJ = '11222333000262';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSefazClinic(withCertificate: false, withCsc: false);
    }

    public function test_the_owner_reads_the_company_data_with_the_resolved_ibge_code(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.id', $this->clinic->id)
            ->assertJsonPath('data.business_name', 'Clinica Veterinaria Teste Ltda')
            ->assertJsonPath('data.cnpj', self::CLINIC_CNPJ)
            ->assertJsonPath('data.city', 'São Paulo')
            ->assertJsonPath('data.state', 'SP')
            ->assertJsonPath('data.zip_code', '01305000')
            ->assertJsonPath('data.resolved_city_ibge_code', '3550308')
            ->assertJsonPath('data.cnpj_locked', false)
            ->assertJsonPath('data.has_certificate', false);
    }

    public function test_the_owner_updates_with_masks_normalized_and_the_change_audited(): void
    {
        $modelLogsBefore = $this->organizationActivity()->where('event', 'updated')->count();

        $this->update([
            'business_name' => 'Clinica Nova Ltda',
            'cnpj' => '11.444.777/0001-61',
            'address' => 'Avenida Paulista',
            'number' => '1000',
            'complement' => 'Sala 2',
            'neighborhood' => 'Bela Vista',
            'city' => 'Campinas',
            'state' => 'sp',
            'zip_code' => '13010-000',
        ])->assertOk()
            ->assertJsonPath('message', 'Dados da empresa atualizados.')
            ->assertJsonPath('data.cnpj', self::OTHER_ROOT_CNPJ)
            ->assertJsonPath('data.state', 'SP')
            ->assertJsonPath('data.zip_code', '13010000')
            ->assertJsonPath('data.resolved_city_ibge_code', '3509502');

        $organization = $this->clinic->fresh();
        $this->assertSame(['Clinica Nova Ltda', self::OTHER_ROOT_CNPJ, 'Sala 2'], [$organization->business_name, $organization->cnpj, $organization->complement]);

        // Um registro só (o `LogsActivity` do model fica mudo nesta edição), com antes e depois.
        $audits = $this->organizationActivity()->where('event', 'organization.company_profile_updated')->get();
        $this->assertCount(1, $audits);
        $audit = $audits->first();
        $this->assertSame(self::CLINIC_CNPJ, $audit->properties['old']['cnpj']);
        $this->assertSame(self::OTHER_ROOT_CNPJ, $audit->properties['new']['cnpj']);
        $this->assertSame('Rua Augusta', $audit->properties['old']['address']);
        $this->assertSame('Avenida Paulista', $audit->properties['new']['address']);
        $this->assertSame($this->owner->id, $audit->causer_id);
        $this->assertSame('organization', $audit->log_name);
        $this->assertSame($modelLogsBefore, $this->organizationActivity()->where('event', 'updated')->count());
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Activity> */
    private function organizationActivity(): \Illuminate\Database\Eloquent\Builder
    {
        return Activity::query()->where('subject_type', Organization::class)->where('subject_id', $this->clinic->id);
    }

    public function test_fields_left_out_of_the_body_are_kept(): void
    {
        $this->update(['business_name' => 'Só o nome', 'cnpj' => self::CLINIC_CNPJ])->assertOk();

        $organization = $this->clinic->fresh();
        $this->assertSame('Só o nome', $organization->business_name);
        $this->assertSame('Rua Augusta', $organization->address);
        $this->assertSame('01305000', $organization->zip_code);
    }

    public function test_a_new_address_refreshes_the_coordinates(): void
    {
        // Sem chave o geocoding nem consulta o provedor (ver `GoogleGeocodingProvider`).
        config(['services.google.maps_api_key' => 'chave-de-teste']);
        Http::fake(['maps.googleapis.com/*' => Http::response([
            'status' => 'OK',
            'results' => [['geometry' => ['location' => ['lat' => -22.9099, 'lng' => -47.0626]]]],
        ])]);

        $this->update($this->payload(['address' => 'Rua Nova', 'city' => 'Campinas', 'zip_code' => '13010000']))->assertOk()
            ->assertJsonPath('data.latitude', -22.9099)
            ->assertJsonPath('data.longitude', -47.0626);
    }

    public function test_an_unchanged_address_does_not_call_the_geocoder(): void
    {
        Http::fake();

        $this->update($this->payload(['business_name' => 'Outro nome']))->assertOk();

        Http::assertNothingSent();
    }

    public function test_receptionist_and_groomer_cannot_read_or_update(): void
    {
        foreach ([$this->receptionist, $this->groomer] as $user) {
            $this->actingAs($user, 'sanctum')->getJson(self::URL)->assertForbidden();
            $this->actingAs($user, 'sanctum')->putJson(self::URL, $this->payload(['business_name' => 'Invasor']))->assertForbidden();
        }

        $this->assertSame('Clinica Veterinaria Teste Ltda', $this->clinic->fresh()->business_name);
    }

    public function test_an_owner_role_holder_who_is_not_owner_of_this_organization_is_forbidden(): void
    {
        // Papel Spatie de dono (passa no middleware), mas só recepcionista NESTA organização.
        $this->receptionist->assignRole('petshop_owner');

        $this->actingAs($this->receptionist, 'sanctum')->getJson(self::URL)->assertForbidden();
        $this->actingAs($this->receptionist, 'sanctum')->putJson(self::URL, $this->payload([]))->assertForbidden();
    }

    public function test_a_professional_without_organization_gets_404(): void
    {
        $loner = User::factory()->professional()->create();
        $loner->assignRole('petshop_owner');

        $this->actingAs($loner, 'sanctum')->getJson(self::URL)->assertNotFound();
        $this->actingAs($loner, 'sanctum')->putJson(self::URL, $this->payload([]))->assertNotFound();
    }

    public function test_invalid_cnpj_uf_and_zip_code_are_refused(): void
    {
        $this->update($this->payload(['cnpj' => '11222333000180']))->assertUnprocessable()->assertJsonValidationErrors('cnpj');
        $this->update($this->payload(['cnpj' => '00000000000000']))->assertUnprocessable()->assertJsonValidationErrors('cnpj');
        $this->update($this->payload(['cnpj' => '1122233300018']))->assertUnprocessable()
            ->assertJsonValidationErrors(['cnpj' => 'O CNPJ deve ter 14 dígitos.']);
        $this->update($this->payload(['state' => 'XX']))->assertUnprocessable()->assertJsonValidationErrors('state');
        $this->update($this->payload(['zip_code' => '1305-000']))->assertUnprocessable()->assertJsonValidationErrors('zip_code');
        $this->update($this->payload(['business_name' => '']))->assertUnprocessable()->assertJsonValidationErrors('business_name');

        $this->assertSame(self::CLINIC_CNPJ, $this->clinic->fresh()->cnpj);
    }

    public function test_a_cnpj_already_used_by_another_organization_is_refused(): void
    {
        Organization::factory()->create(['cnpj' => self::OTHER_ROOT_CNPJ]);

        $this->update($this->payload(['cnpj' => self::OTHER_ROOT_CNPJ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['cnpj' => 'Este CNPJ já está cadastrado em outra empresa do 2pets.']);
    }

    public function test_the_cnpj_is_locked_after_an_authorized_fiscal_document(): void
    {
        FiscalDocument::create([
            'organization_id' => $this->clinic->id,
            'professional_id' => $this->owner->id,
            'kind' => 'nfce',
            'status' => FiscalDocumentStatus::AUTHORIZED->value,
            'provider' => \App\Services\Fiscal\Sefaz\SefazFiscalGateway::NAME,
            'total' => 100,
        ]);

        $this->actingAs($this->owner, 'sanctum')->getJson(self::URL)->assertOk()->assertJsonPath('data.cnpj_locked', true);
        $message = $this->update($this->payload(['cnpj' => self::BRANCH_CNPJ]))->assertUnprocessable()
            ->assertJsonValidationErrors('cnpj')->json('errors.cnpj.0');
        $this->assertStringContainsString('nota fiscal emitida', $message);
        $this->assertSame(self::CLINIC_CNPJ, $this->clinic->fresh()->cnpj);

        // O resto do cadastro continua editável com o mesmo CNPJ.
        $this->update($this->payload(['complement' => 'Fundos']))->assertOk()->assertJsonPath('data.complement', 'Fundos');
    }

    public function test_a_simulated_authorized_document_does_not_lock_the_cnpj(): void
    {
        FiscalDocument::create([
            'organization_id' => $this->clinic->id,
            'professional_id' => $this->owner->id,
            'kind' => 'nfce',
            'status' => FiscalDocumentStatus::AUTHORIZED->value,
            'provider' => \App\Services\Fiscal\LogFiscalProviderGateway::NAME,
            'total' => 100,
        ]);

        $this->actingAs($this->owner, 'sanctum')->getJson(self::URL)->assertOk()->assertJsonPath('data.cnpj_locked', false);
        $this->update($this->payload(['cnpj' => self::OTHER_ROOT_CNPJ]))->assertOk()->assertJsonPath('data.cnpj', self::OTHER_ROOT_CNPJ);
    }

    public function test_a_rejected_document_does_not_lock_the_cnpj(): void
    {
        FiscalDocument::create([
            'organization_id' => $this->clinic->id,
            'professional_id' => $this->owner->id,
            'kind' => 'nfe',
            'status' => FiscalDocumentStatus::REJECTED->value,
            'total' => 100,
        ]);

        $this->update($this->payload(['cnpj' => self::OTHER_ROOT_CNPJ]))->assertOk()->assertJsonPath('data.cnpj', self::OTHER_ROOT_CNPJ);
    }

    public function test_a_certificate_of_another_cnpj_root_must_be_removed_first(): void
    {
        $this->uploadCertificate();

        $message = $this->update($this->payload(['cnpj' => self::OTHER_ROOT_CNPJ]))->assertUnprocessable()
            ->assertJsonValidationErrors('cnpj')->json('errors.cnpj.0');

        $this->assertStringContainsString('Remova o certificado', $message);
        $this->assertSame(self::CLINIC_CNPJ, $this->clinic->fresh()->cnpj);
    }

    public function test_a_branch_cnpj_with_the_same_root_keeps_the_certificate_and_updates_the_match(): void
    {
        $this->uploadCertificate();

        $this->update($this->payload(['cnpj' => self::BRANCH_CNPJ]))->assertOk()
            ->assertJsonPath('data.cnpj', self::BRANCH_CNPJ)
            ->assertJsonPath('data.has_certificate', true);

        $this->assertSame(FiscalCertificateMatch::ROOT, FiscalCertificate::query()->where('organization_id', $this->clinic->id)->firstOrFail()->cnpj_match);
    }

    public function test_filling_cnpj_and_address_clears_them_from_the_transmission_readiness(): void
    {
        $this->clinic->forceFill([
            'cnpj' => null, 'address' => null, 'number' => null, 'neighborhood' => null,
            'city' => null, 'state' => null, 'zip_code' => null,
        ])->save();
        $before = $this->missing();
        $this->assertContains('cnpj', $before);
        $this->assertContains('address', $before);
        $this->assertContains('city_ibge_code', $before);

        $this->update($this->payload([]))->assertOk();

        $after = $this->missing();
        $this->assertNotContains('cnpj', $after);
        $this->assertNotContains('address', $after);
        $this->assertNotContains('city_ibge_code', $after);
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides): array
    {
        return $overrides + [
            'business_name' => 'Clinica Veterinaria Teste Ltda',
            'cnpj' => self::CLINIC_CNPJ,
            'address' => 'Rua Augusta',
            'number' => '100',
            'complement' => null,
            'neighborhood' => 'Consolacao',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01305000',
        ];
    }

    /** @param  array<string, mixed>  $body */
    private function update(array $body): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->putJson(self::URL, $body);
    }

    /** @return list<string> */
    private function missing(): array
    {
        return $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/fiscal-settings')->assertOk()->json('data.transmission.missing');
    }

    private function uploadCertificate(): void
    {
        app(\App\Services\Fiscal\Certificate\FiscalCertificateService::class)
            ->upload($this->clinic->fresh(), \Tests\Support\Fiscal\TestCertificate::pfx(self::CLINIC_CNPJ), \Tests\Support\Fiscal\TestCertificate::PASSWORD, $this->owner);
    }
}
