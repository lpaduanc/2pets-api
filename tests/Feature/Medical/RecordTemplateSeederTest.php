<?php

namespace Tests\Feature\Medical;

use App\Models\Organization;
use App\Models\RecordTemplate;
use Database\Seeders\RecordTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Modelo de anamnese por sistemas (PLANO 3.8; doc 14): o seeder cria uma vez só, o modelo é
 * global e aparece na listagem de modelos de prontuário para o veterinário. Quem não escreve
 * prontuário não lista.
 */
class RecordTemplateSeederTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    public function test_seeder_is_idempotent_and_the_template_is_listed_for_a_vet(): void
    {
        $this->buildClinic();
        $this->seed(RecordTemplateSeeder::class);
        $this->seed(RecordTemplateSeeder::class);
        $template = RecordTemplate::where('system_key', RecordTemplateSeeder::ANAMNESIS_BY_SYSTEMS)->sole();
        $this->assertNull($template->organization_id);

        foreach (['Queixa principal', 'Histórico', 'Tegumentar', 'Digestório', 'Respiratório', 'Cardiovascular', 'Urinário', 'Reprodutor', 'Nervoso', 'Musculoesquelético', 'Oftálmico', 'Auditivo'] as $section) {
            $this->assertStringContainsString($section, $template->body_html);
        }

        // Modelo da própria clínica e de outra organização: só o próprio aparece junto do global.
        RecordTemplate::create(['organization_id' => $this->clinic->id, 'name' => 'Retorno', 'body_html' => '<p>x</p>']);
        RecordTemplate::create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Deles', 'body_html' => '<p>y</p>']);

        $vet = $this->veterinarian();
        $list = $this->actingAs($vet, 'sanctum')->getJson('/api/record-templates')->assertOk();
        $this->assertSame(['Retorno', 'Anamnese por sistemas'], collect($list->json('data'))->pluck('name')->all());
        $this->assertTrue(collect($list->json('data'))->firstWhere('id', $template->id)['is_global']);
        $this->getJson("/api/record-templates/{$template->id}")->assertOk()->assertJsonPath('data.name', 'Anamnese por sistemas')
            ->assertJsonPath('data.body_html', $template->body_html);
        $this->getJson('/api/record-templates/'.RecordTemplate::where('name', 'Deles')->value('id'))->assertNotFound();

        // Sem permissão de escrever prontuário (aqui, um tutor): 403.
        $this->actingAs($this->tutor, 'sanctum')->getJson('/api/record-templates')->assertForbidden();
    }

    private function veterinarian(): \App\Models\User
    {
        $vet = \App\Models\User::factory()->professional()->create();
        \App\Models\OrganizationMember::factory()->for($this->clinic, 'organization')->create(['user_id' => $vet->id, 'role' => \App\Enums\OrganizationRole::VETERINARIAN->value]);

        return $vet;
    }
}
