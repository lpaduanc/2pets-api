<?php

namespace App\DataTransferObjects;

/**
 * Extrai limite inferior/superior de um texto livre de faixa de referência de exame
 * ("100-1250", "12.0-18.0", "100–1.250", "0,5 a 1,5"). Quando o texto não segue o padrão
 * "número separador número" — faixa aberta ("> 5", "até 10"), texto livre, ou vazio — os
 * dois limites ficam `null`. Nunca adivinha metade da faixa: sem os dois números não dá
 * para derivar `status` com segurança (ver `ExamResultStatus::deriveFrom`).
 */
final readonly class ExamReferenceRange
{
    private const BOUNDS_PATTERN = '/^\s*(-?[\d.,]+)\s*(?:-|–|—|a)\s*(-?[\d.,]+)\s*$/ui';

    public ?float $min;

    public ?float $max;

    public function __construct(public ?string $raw)
    {
        [$this->min, $this->max] = $this->parseBounds();
    }

    /**
     * @return array{0: ?float, 1: ?float}
     */
    private function parseBounds(): array
    {
        if ($this->raw === null || ! preg_match(self::BOUNDS_PATTERN, $this->raw, $matches)) {
            return [null, null];
        }

        $min = PtBrDecimal::parse($matches[1]);
        $max = PtBrDecimal::parse($matches[2]);

        return $min !== null && $max !== null ? [$min, $max] : [null, null];
    }
}
