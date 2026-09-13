<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Substitui a regra nativa `prohibited` para um campo de capacidade não aplicável ao tipo
 * de profissional selecionado (`ProfessionalCapabilityRegistry`).
 *
 * Bug real reportado em produção: `prohibited` do Laravel só considera "vazio" `null`,
 * string vazia e array vazio — `false` e `0` contam como "valor presente" e por isso
 * FALHAM. Um formulário que sempre serializa o campo (mesmo escondido pela UI) manda
 * `parking_available: false` para um veterinário volante por padrão — o front escondia o
 * campo corretamente, mas `prohibited` rejeitava o `false` como se fosse uma tentativa de
 * burlar a trava, travando o cadastro de quem simplesmente NÃO tem a característica.
 *
 * Aqui só um valor REALMENTE afirmativo é rejeitado: `true` para booleano, um inteiro
 * diferente de zero para contagem (via `$neutralValues`). `null`, string vazia, array
 * vazio e os valores extras informados sempre passam — e não devem ser persistidos (quem
 * decide isso é `ProfessionalCapabilityFieldExtractor`, não esta regra).
 */
final class ProhibitedCapabilityValue implements ValidationRule
{
    /** @param list<mixed> $neutralValues valores além de null/''/[]/false tratados como "não respondido" */
    public function __construct(
        private readonly string $message,
        private readonly array $neutralValues = [],
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->isNeutral($value)) {
            return;
        }

        $fail($this->message);
    }

    /**
     * `false` é neutro por padrão (SEMPRE, não só via `$neutralValues`) — é o próprio caso
     * que motivou esta regra: o valor "desligado" de um campo booleano de capacidade.
     */
    private function isNeutral(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false
            || in_array($value, $this->neutralValues, true);
    }
}
