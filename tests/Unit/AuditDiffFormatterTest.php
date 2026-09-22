<?php

namespace Tests\Unit;

use App\Services\Audit\AuditDiffFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Item 22 do backlog gap-simplesvet — tradução pt-BR do diff de activity log. Critério de
 * aceite literal: alterar o peso de um pet gera "Peso: 12,5 kg → 13,2 kg", não JSON cru.
 */
class AuditDiffFormatterTest extends TestCase
{
    private AuditDiffFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatter = new AuditDiffFormatter;
    }

    public function test_weight_change_is_formatted_in_kilograms_with_comma_decimal(): void
    {
        $rows = $this->formatter->diff(['weight' => 12.5], ['weight' => 13.2]);

        $this->assertCount(1, $rows);
        $this->assertSame('Peso: 12,5 kg → 13,2 kg', $rows[0]['text']);
    }

    public function test_boolean_change_is_translated_to_sim_nao(): void
    {
        $rows = $this->formatter->diff(['active' => true], ['active' => false]);

        $this->assertSame('Ativo: Sim → Não', $rows[0]['text']);
    }

    public function test_unchanged_fields_are_omitted(): void
    {
        $rows = $this->formatter->diff(['name' => 'Rex', 'weight' => 10.0], ['name' => 'Rex', 'weight' => 11.0]);

        $this->assertCount(1, $rows);
        $this->assertSame('weight', $rows[0]['field']);
    }

    public function test_sensitive_fields_never_appear_in_the_diff_even_if_logged_by_mistake(): void
    {
        $rows = $this->formatter->diff(
            ['password' => 'old-hash', 'name' => 'Rex'],
            ['password' => 'new-hash', 'name' => 'Rex 2'],
        );

        $fields = array_column($rows, 'field');
        $this->assertNotContains('password', $fields);
        $this->assertContains('name', $fields);
    }

    public function test_money_in_cents_is_converted_to_reais(): void
    {
        $rows = $this->formatter->diff(['total_cents' => 1000], ['total_cents' => 2550]);

        $this->assertSame('Total Cents: R$ 10,00 → R$ 25,50', $rows[0]['text']);
    }
}
