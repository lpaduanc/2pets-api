<?php

namespace Database\Seeders\Dataset;

/**
 * Praças da região beta do MVP (`CLAUDE.md` §PRIORIDADE: SP + Grande SP + Campinas) com
 * centroide real, peso de densidade e faixa de CEP.
 *
 * O peso existe porque busca geográfica com distribuição uniforme mente: se cada praça
 * tivesse o mesmo número de profissionais, um raio de 5 km na Avenida Paulista devolveria o
 * mesmo que um raio de 5 km em Cotia, e nenhuma decisão de produto tomada em cima disso
 * valeria. A proporção aqui segue, de forma grosseira, a população das praças.
 *
 * O raio de dispersão é em GRAUS e por praça: São Paulo espalha ~0,09° (≈10 km) porque a
 * cidade é grande; Diadema espalha 0,02° porque é pequena. Dispersão única para todas
 * colocaria profissional de Diadema dentro de São Bernardo.
 */
final class BetaRegionGeography
{
    /**
     * @return list<array{city: string, state: string, lat: float, lng: float, spread: float, weight: int, zip: string}>
     */
    public static function places(): array
    {
        return [
            self::place('São Paulo', -23.5505, -46.6333, 0.090, 40, '01310'),
            self::place('Guarulhos', -23.4538, -46.5333, 0.045, 8, '07010'),
            self::place('Campinas', -22.9099, -47.0626, 0.055, 12, '13010'),
            self::place('São Bernardo do Campo', -23.6939, -46.5650, 0.035, 7, '09710'),
            self::place('Santo André', -23.6639, -46.5383, 0.030, 6, '09010'),
            self::place('Osasco', -23.5329, -46.7916, 0.028, 5, '06010'),
            self::place('São José dos Campos', -23.1791, -45.8872, 0.045, 5, '12210'),
            self::place('Barueri', -23.5106, -46.8761, 0.030, 4, '06401'),
            self::place('Diadema', -23.6861, -46.6228, 0.020, 3, '09910'),
            self::place('Mauá', -23.6677, -46.4613, 0.025, 3, '09310'),
            self::place('Cotia', -23.6039, -46.9189, 0.035, 3, '06700'),
            self::place('Valinhos', -22.9709, -46.9959, 0.025, 2, '13270'),
            self::place('Sumaré', -22.8219, -47.2669, 0.028, 2, '13170'),
        ];
    }

    /**
     * Uma praça repetida `weight` vezes — sortear um índice uniforme desta lista já produz a
     * distribuição ponderada, sem soma acumulada nem busca binária no seeder.
     *
     * @return list<array{city: string, state: string, lat: float, lng: float, spread: float, weight: int, zip: string}>
     */
    public static function weightedPool(): array
    {
        $pool = [];

        foreach (self::places() as $place) {
            $pool = [...$pool, ...array_fill(0, $place['weight'], $place)];
        }

        return $pool;
    }

    /**
     * @return array{city: string, state: string, lat: float, lng: float, spread: float, weight: int, zip: string}
     */
    private static function place(string $city, float $lat, float $lng, float $spread, int $weight, string $zip): array
    {
        return [
            'city' => $city,
            'state' => 'SP',
            'lat' => $lat,
            'lng' => $lng,
            'spread' => $spread,
            'weight' => $weight,
            'zip' => $zip,
        ];
    }
}
