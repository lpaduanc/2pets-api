<?php

namespace App\DataTransferObjects\Crm;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Junta as 3 fontes do "marco de retorno" (Appointment completo, Invoice paga, Sale paga) —
 * contrato `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`, regra de negócio 2.
 * `first`/`last` são o menor/maior entre as três fontes; os gastos são SOMA de Invoice+Sale
 * (Appointment não tem valor monetário próprio, o faturamento é que carrega isso).
 */
final readonly class ClientInteractionSummary
{
    public function __construct(
        public ?CarbonInterface $firstInteractionAt,
        public ?CarbonInterface $lastInteractionAt,
        public float $spent365,
        public float $spent90,
        public float $spent30,
    ) {}

    public static function empty(): self
    {
        return new self(null, null, 0.0, 0.0, 0.0);
    }

    /**
     * Cada argumento é uma linha (ou `null`) de uma query agregada com as colunas
     * `first_at`/`last_at` e, para fatura/venda, também `spent_365`/`spent_90`/`spent_30`.
     */
    public static function merge(?object $appointment, ?object $invoice, ?object $sale): self
    {
        return new self(
            firstInteractionAt: self::earliest([$appointment?->first_at, $invoice?->first_at, $sale?->first_at]),
            lastInteractionAt: self::latest([$appointment?->last_at, $invoice?->last_at, $sale?->last_at]),
            spent365: self::sum($invoice?->spent_365, $sale?->spent_365),
            spent90: self::sum($invoice?->spent_90, $sale?->spent_90),
            spent30: self::sum($invoice?->spent_30, $sale?->spent_30),
        );
    }

    private static function sum(mixed $a, mixed $b): float
    {
        return (float) ($a ?? 0) + (float) ($b ?? 0);
    }

    /** @param  list<string|null>  $dates */
    private static function earliest(array $dates): ?CarbonInterface
    {
        return self::parsedDates($dates)->min();
    }

    /** @param  list<string|null>  $dates */
    private static function latest(array $dates): ?CarbonInterface
    {
        return self::parsedDates($dates)->max();
    }

    /** @param  list<string|null>  $dates */
    private static function parsedDates(array $dates): \Illuminate\Support\Collection
    {
        return collect($dates)->filter()->map(fn (string $date): Carbon => Carbon::parse($date));
    }
}
