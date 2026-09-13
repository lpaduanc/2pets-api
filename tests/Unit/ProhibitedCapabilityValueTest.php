<?php

namespace Tests\Unit;

use App\Rules\ProhibitedCapabilityValue;
use PHPUnit\Framework\TestCase;

/**
 * Bug real reportado em produção (ver `HasProfessionalCapabilityRules`): a regra nativa
 * `prohibited` do Laravel só considera `null`/`''`/`[]` como vazio — `false` e `0` contam
 * como "valor presente" e travavam o cadastro de quem simplesmente não tem a
 * característica. Esta regra substitui `prohibited` nos campos de capacidade e só rejeita
 * um valor REALMENTE afirmativo.
 */
class ProhibitedCapabilityValueTest extends TestCase
{
    public function test_accepts_null_empty_string_and_empty_array(): void
    {
        $rule = new ProhibitedCapabilityValue('não permitido');

        $this->assertPasses($rule, null);
        $this->assertPasses($rule, '');
        $this->assertPasses($rule, []);
    }

    public function test_accepts_false_unlike_the_native_prohibited_rule(): void
    {
        $this->assertPasses(new ProhibitedCapabilityValue('não permitido'), false);
    }

    public function test_rejects_true_with_the_given_message(): void
    {
        $rule = new ProhibitedCapabilityValue('não permitido para este tipo');

        $failures = [];
        $rule->validate('parking_available', true, function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        $this->assertSame(['não permitido para este tipo'], $failures);
    }

    public function test_accepts_configured_neutral_values_such_as_zero(): void
    {
        $rule = new ProhibitedCapabilityValue('não permitido', neutralValues: [0]);

        $this->assertPasses($rule, 0);
    }

    public function test_rejects_a_non_neutral_value_even_when_neutral_values_are_configured(): void
    {
        $rule = new ProhibitedCapabilityValue('não permitido', neutralValues: [0]);

        $failures = [];
        $rule->validate('exam_rooms_count', 3, function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

        $this->assertSame(['não permitido'], $failures);
    }

    private function assertPasses(ProhibitedCapabilityValue $rule, mixed $value): void
    {
        $rule->validate('field', $value, function (string $message): void {
            $this->fail("Não deveria falhar, mas falhou com: {$message}");
        });

        $this->addToAssertionCount(1);
    }
}
