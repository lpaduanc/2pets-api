<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\Pet;
use App\Models\User;
use App\Services\Document\DocumentTemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `DocumentTemplateRenderer` — contrato docs/gap-simplesvet/specs/
 * 15-modelos-documento-receituario-assinatura-spec.md, regra de negócio 2: substituição por
 * whitelist fixa (`strtr()`), NUNCA um engine de template genérico. Bloqueante de segurança
 * (revisão do `security-specialist`) — este teste é a rede de segurança contra regressão.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`.
 */
class DocumentTemplateRendererTest extends TestCase
{
    use RefreshDatabase;

    private DocumentTemplateRenderer $renderer;

    private User $professional;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new DocumentTemplateRenderer;
        $tutor = User::factory()->tutor()->create(['name' => 'Maria Silva']);
        $this->professional = User::factory()->professional()->create(['name' => 'Dra. Helena Prado']);
        $this->pet = Pet::factory()->create(['user_id' => $tutor->id, 'name' => 'Bella']);
    }

    public function test_replaces_known_placeholders_with_real_data(): void
    {
        $organization = Organization::factory()->create(['name' => 'Clínica Quatro Patas']);

        $rendered = $this->renderer->render(
            '<p>Atesto que {{pet.nome}}, de {{tutor.nome}}, foi atendido por {{profissional.nome}} em {{clinica.nome}} no dia {{data}}.</p>',
            $this->pet,
            $this->professional,
            $organization,
            Carbon::parse('2026-10-27'),
        );

        $this->assertStringContainsString('Bella', $rendered);
        $this->assertStringContainsString('Maria Silva', $rendered);
        $this->assertStringContainsString('Dra. Helena Prado', $rendered);
        $this->assertStringContainsString('Clínica Quatro Patas', $rendered);
        $this->assertStringContainsString('27/10/2026', $rendered);
    }

    /**
     * Nenhuma expressão é avaliada — só a whitelist fixa de chaves conhecidas. `{{ 7*7 }}`
     * (tentativa de SSTI clássica) e uma tag PHP literal permanecem intocadas na saída.
     */
    public function test_unknown_placeholder_and_expression_attempts_stay_literal(): void
    {
        $rendered = $this->renderer->render(
            '<p>{{ 7*7 }} {{nao_existe}} <?php echo "hacked"; ?></p>',
            $this->pet,
            $this->professional,
            null,
            Carbon::now(),
        );

        $this->assertStringContainsString('{{ 7*7 }}', $rendered);
        $this->assertStringContainsString('{{nao_existe}}', $rendered);
        $this->assertStringContainsString('<?php echo "hacked"; ?>', $rendered);
        $this->assertStringNotContainsString('49', $rendered);
        $this->assertStringNotContainsString('hacked', $rendered);
    }

    /**
     * Defesa em profundidade: um nome de pet/tutor contendo HTML/script é escapado, não
     * interpretado — mesmo vindo de um valor real do domínio, não de uma tentativa direta no
     * corpo do template.
     */
    public function test_pet_name_containing_html_is_escaped(): void
    {
        $this->pet->forceFill(['name' => '<script>alert(1)</script>'])->save();

        $rendered = $this->renderer->render(
            '<p>{{pet.nome}}</p>',
            $this->pet->fresh(),
            $this->professional,
            null,
            Carbon::now(),
        );

        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }

    public function test_missing_organization_falls_back_to_an_em_dash(): void
    {
        $rendered = $this->renderer->render(
            '{{clinica.nome}}',
            $this->pet,
            $this->professional,
            null,
            Carbon::now(),
        );

        $this->assertSame('—', $rendered);
    }
}
