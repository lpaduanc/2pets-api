<?php

namespace App\Services\Fiscal\Calculation;

/**
 * Lacunas de numeração e validação da faixa a inutilizar — spec docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §3.7. Pura (golden `fiscal-inut-01`).
 *
 * "Usado" = número de documento que a SEFAZ considera consumido (todo status menos rejeitado).
 * Lacuna = número entre o primeiro emitido por este sistema na série e o próximo a emitir que não
 * foi usado nem inutilizado.
 */
final class FiscalNumberGaps
{
    public const REASON_MIN_LENGTH = 15;

    public const REASON_MAX_LENGTH = 255;

    public const VIOLATION_ORDER = 'order';

    public const VIOLATION_REASON_LENGTH = 'reason_length';

    public const VIOLATION_OVERLAP = 'overlap';

    public const VIOLATION_USED_NUMBER = 'used_number';

    /**
     * @param  list<int>  $used
     * @param  list<array{0: int, 1: int}>  $invalidated  faixas pendentes/homologadas
     * @return list<array{from: int, to: int}>
     */
    public function gaps(array $used, array $invalidated, int $firstNumber, int $nextNumber): array
    {
        $taken = array_flip($used);
        $gaps = [];
        $start = null;

        for ($number = max(1, $firstNumber); $number < $nextNumber; $number++) {
            $isGap = ! isset($taken[$number]) && ! $this->inAnyRange($number, $invalidated);
            [$start, $gaps] = $this->advance($isGap, $number, $start, $gaps);
        }

        return $start === null ? $gaps : [...$gaps, ['from' => $start, 'to' => $nextNumber - 1]];
    }

    /**
     * @param  list<int>  $used
     * @param  list<array{0: int, 1: int}>  $invalidated
     * @return list<string> códigos `VIOLATION_*`, vazio = faixa válida
     */
    public function violations(int $from, int $to, string $reason, array $used, array $invalidated): array
    {
        $length = mb_strlen(trim($reason));

        return array_values(array_keys(array_filter([
            self::VIOLATION_ORDER => $from < 1 || $from > $to,
            self::VIOLATION_REASON_LENGTH => $length < self::REASON_MIN_LENGTH || $length > self::REASON_MAX_LENGTH,
            self::VIOLATION_OVERLAP => $this->overlapsAny($from, $to, $invalidated),
            self::VIOLATION_USED_NUMBER => array_filter($used, fn (int $number): bool => $number >= $from && $number <= $to) !== [],
        ])));
    }

    /**
     * @param  list<array{from: int, to: int}>  $gaps
     * @return array{0: int|null, 1: list<array{from: int, to: int}>}
     */
    private function advance(bool $isGap, int $number, ?int $start, array $gaps): array
    {
        if ($isGap) {
            return [$start ?? $number, $gaps];
        }

        return $start === null ? [null, $gaps] : [null, [...$gaps, ['from' => $start, 'to' => $number - 1]]];
    }

    /** @param  list<array{0: int, 1: int}>  $ranges */
    private function inAnyRange(int $number, array $ranges): bool
    {
        return array_filter($ranges, fn (array $range): bool => $number >= $range[0] && $number <= $range[1]) !== [];
    }

    /** @param  list<array{0: int, 1: int}>  $ranges */
    private function overlapsAny(int $from, int $to, array $ranges): bool
    {
        return array_filter($ranges, fn (array $range): bool => $from <= $range[1] && $to >= $range[0]) !== [];
    }
}
