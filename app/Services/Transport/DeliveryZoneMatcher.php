<?php

namespace App\Services\Transport;

use App\Models\ServiceArea;
use Illuminate\Support\Collection;

/**
 * Escolha da zona do leva e traz (PLANO 3.7). Classe pura (sem banco nem geocodificação).
 *
 *  - Por CEP: a zona cuja lista tem o CEP (exato) ou uma faixa `inicio..fim` que o contém. Mais
 *    de uma zona casa → a de MENOR taxa (o cliente nunca paga mais por sobreposição de cadastro);
 *    empate → a de menor id.
 *  - Por distância: entre as zonas de raio, a de MENOR `max_km` que cobre a distância — as
 *    faixas ficam "até 5 km", "até 10 km"... sem precisar de mínimo.
 */
final class DeliveryZoneMatcher
{
    /** @param  Collection<int, ServiceArea>  $zones */
    public function byCep(Collection $zones, string $cep): ?ServiceArea
    {
        $target = self::digits($cep);

        if (strlen($target) !== 8) {
            return null;
        }

        return $zones->filter(fn (ServiceArea $zone): bool => $zone->delivery_rule_type === 'cep' && $this->contains((array) $zone->delivery_ceps, (int) $target))
            ->sortBy([fn ($a, $b) => (float) $a->delivery_fee <=> (float) $b->delivery_fee, fn ($a, $b) => $a->id <=> $b->id])
            ->first();
    }

    /** @param  Collection<int, ServiceArea>  $zones */
    public function byDistance(Collection $zones, float $distanceKm): ?ServiceArea
    {
        return $zones->filter(fn (ServiceArea $zone): bool => $zone->delivery_rule_type === 'distance' && $zone->delivery_max_km !== null && (float) $zone->delivery_max_km >= $distanceKm)
            ->sortBy([fn ($a, $b) => (float) $a->delivery_max_km <=> (float) $b->delivery_max_km, fn ($a, $b) => $a->id <=> $b->id])
            ->first();
    }

    public static function digits(?string $cep): string
    {
        return preg_replace('/\D/', '', (string) $cep) ?? '';
    }

    /** @param  list<string>  $rules  "01000-000..01999-999" ou "04500-000" */
    private function contains(array $rules, int $cep): bool
    {
        foreach ($rules as $rule) {
            $parts = array_map(fn (string $part): int => (int) self::digits($part), explode('..', $rule));
            [$from, $to] = [$parts[0], $parts[1] ?? $parts[0]];

            if ($cep >= min($from, $to) && $cep <= max($from, $to)) {
                return true;
            }
        }

        return false;
    }
}
