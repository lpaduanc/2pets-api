<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/reference/prescription-alerts` — contrato
 * docs/atendimento-veterinario/03-contrato-receituario.md §5. Endpoint público (sem PII),
 * serve a lista curada de `config/clinical-alerts.php` — o match em si é calculado no
 * frontend, este endpoint só entrega o dado.
 */
class PrescriptionAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_curated_species_and_chronic_condition_lists(): void
    {
        $response = $this->getJson('/api/reference/prescription-alerts');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'species_substance' => [
                        '*' => ['substance', 'aliases', 'species', 'severity', 'message_key', 'message'],
                    ],
                    'chronic_condition_substance' => [
                        '*' => ['substance', 'aliases', 'condition_terms', 'severity', 'message_key', 'message'],
                    ],
                ],
            ]);

        $paracetamol = collect($response->json('data.species_substance'))
            ->firstWhere('substance', 'paracetamol');

        $this->assertNotNull($paracetamol, 'A entrada de paracetamol×gato precisa estar na lista curada.');
        $this->assertSame(['cat'], $paracetamol['species']);
        $this->assertSame('high', $paracetamol['severity']);
    }

    public function test_does_not_require_authentication(): void
    {
        $this->getJson('/api/reference/prescription-alerts')->assertOk();
    }

    /** Dipirona foi removida deliberadamente da lista curada (Apêndice A.3 do doc de domínio). */
    public function test_does_not_list_dipyrone_as_a_species_risk(): void
    {
        $substances = collect($this->getJson('/api/reference/prescription-alerts')->json('data.species_substance'))
            ->pluck('substance');

        $this->assertFalse($substances->contains('dipirona'));
        $this->assertFalse($substances->contains('metamizol'));
    }

    public function test_severity_is_never_anything_other_than_high_or_medium(): void
    {
        $response = $this->getJson('/api/reference/prescription-alerts');

        $severities = collect($response->json('data.species_substance'))
            ->merge($response->json('data.chronic_condition_substance'))
            ->pluck('severity')
            ->unique();

        $this->assertEmpty($severities->diff(['high', 'medium']));
    }
}
