<?php

namespace Database\Seeders\Dataset;

/**
 * Praças do Brasil inteiro (as 27 UFs) para o dataset de VOLUME da busca: centroide real,
 * dispersão em graus, peso de densidade, faixa de CEP e DDD.
 *
 * Existe ao lado de `BetaRegionGeography` porque o objetivo é outro: aquela concentra o beta
 * (SP + Campinas) para avaliar relevância; esta espalha 100 mil cadastros pelo país para
 * medir latência com endereços de qualquer capital. O peso segue, grosseiramente, a
 * população — um raio de 10 km em São Paulo precisa devolver muito mais que um em Palmas,
 * senão o teste de desempenho mede um cenário que não existe.
 */
final class NationalGeography
{
    /**
     * @return list<array{city: string, state: string, lat: float, lng: float, spread: float, weight: int, zip: string, ddd: string}>
     */
    public static function places(): array
    {
        return [
            self::place('São Paulo', 'SP', -23.5505, -46.6333, 0.090, 30, '01310', '11'),
            self::place('Campinas', 'SP', -22.9099, -47.0626, 0.055, 5, '13010', '19'),
            self::place('Guarulhos', 'SP', -23.4538, -46.5333, 0.045, 4, '07010', '11'),
            self::place('Santos', 'SP', -23.9608, -46.3336, 0.030, 3, '11010', '13'),
            self::place('Ribeirão Preto', 'SP', -21.1775, -47.8103, 0.045, 3, '14010', '16'),
            self::place('São José dos Campos', 'SP', -23.1791, -45.8872, 0.045, 3, '12210', '12'),
            self::place('Sorocaba', 'SP', -23.5015, -47.4526, 0.040, 3, '18010', '15'),
            self::place('Rio de Janeiro', 'RJ', -22.9068, -43.1729, 0.090, 18, '20040', '21'),
            self::place('Niterói', 'RJ', -22.8832, -43.1034, 0.030, 3, '24020', '21'),
            self::place('Petrópolis', 'RJ', -22.5112, -43.1779, 0.030, 2, '25610', '24'),
            self::place('Belo Horizonte', 'MG', -19.9167, -43.9345, 0.070, 9, '30110', '31'),
            self::place('Uberlândia', 'MG', -18.9186, -48.2772, 0.050, 3, '38400', '34'),
            self::place('Juiz de Fora', 'MG', -21.7642, -43.3496, 0.040, 2, '36010', '32'),
            self::place('Vitória', 'ES', -20.3155, -40.3128, 0.030, 3, '29010', '27'),
            self::place('Vila Velha', 'ES', -20.3297, -40.2925, 0.030, 2, '29100', '27'),
            self::place('Curitiba', 'PR', -25.4284, -49.2733, 0.070, 7, '80010', '41'),
            self::place('Londrina', 'PR', -23.3045, -51.1696, 0.045, 3, '86010', '43'),
            self::place('Maringá', 'PR', -23.4205, -51.9333, 0.040, 2, '87010', '44'),
            self::place('Florianópolis', 'SC', -27.5954, -48.5480, 0.050, 4, '88010', '48'),
            self::place('Joinville', 'SC', -26.3045, -48.8487, 0.045, 3, '89201', '47'),
            self::place('Blumenau', 'SC', -26.9194, -49.0661, 0.035, 2, '89010', '47'),
            self::place('Porto Alegre', 'RS', -30.0346, -51.2177, 0.070, 7, '90010', '51'),
            self::place('Caxias do Sul', 'RS', -29.1678, -51.1794, 0.040, 3, '95010', '54'),
            self::place('Pelotas', 'RS', -31.7654, -52.3376, 0.035, 2, '96010', '53'),
            self::place('Brasília', 'DF', -15.7939, -47.8828, 0.090, 8, '70040', '61'),
            self::place('Goiânia', 'GO', -16.6869, -49.2648, 0.060, 6, '74003', '62'),
            self::place('Anápolis', 'GO', -16.3281, -48.9530, 0.030, 2, '75020', '62'),
            self::place('Cuiabá', 'MT', -15.6014, -56.0979, 0.045, 3, '78005', '65'),
            self::place('Campo Grande', 'MS', -20.4697, -54.6201, 0.050, 3, '79002', '67'),
            self::place('Salvador', 'BA', -12.9777, -38.5016, 0.070, 8, '40010', '71'),
            self::place('Feira de Santana', 'BA', -12.2664, -38.9663, 0.040, 2, '44001', '75'),
            self::place('Recife', 'PE', -8.0476, -34.8770, 0.060, 6, '50010', '81'),
            self::place('Jaboatão dos Guararapes', 'PE', -8.1130, -35.0154, 0.030, 2, '54400', '81'),
            self::place('Fortaleza', 'CE', -3.7319, -38.5267, 0.070, 7, '60010', '85'),
            self::place('João Pessoa', 'PB', -7.1195, -34.8450, 0.040, 3, '58010', '83'),
            self::place('Natal', 'RN', -5.7945, -35.2110, 0.040, 3, '59010', '84'),
            self::place('Maceió', 'AL', -9.6658, -35.7353, 0.040, 3, '57010', '82'),
            self::place('Aracaju', 'SE', -10.9472, -37.0731, 0.035, 2, '49010', '79'),
            self::place('Teresina', 'PI', -5.0892, -42.8019, 0.045, 3, '64000', '86'),
            self::place('São Luís', 'MA', -2.5307, -44.3068, 0.045, 3, '65010', '98'),
            self::place('Belém', 'PA', -1.4558, -48.4902, 0.050, 4, '66010', '91'),
            self::place('Santarém', 'PA', -2.4385, -54.6996, 0.030, 1, '68005', '93'),
            self::place('Manaus', 'AM', -3.1190, -60.0217, 0.070, 5, '69005', '92'),
            self::place('Macapá', 'AP', 0.0349, -51.0694, 0.030, 1, '68900', '96'),
            self::place('Boa Vista', 'RR', 2.8235, -60.6758, 0.030, 1, '69301', '95'),
            self::place('Rio Branco', 'AC', -9.9754, -67.8249, 0.030, 1, '69900', '68'),
            self::place('Porto Velho', 'RO', -8.7612, -63.9004, 0.035, 1, '76801', '69'),
            self::place('Palmas', 'TO', -10.1840, -48.3336, 0.035, 1, '77001', '63'),
        ];
    }

    /**
     * Mesma técnica de `BetaRegionGeography::weightedPool()`: a praça repetida `weight` vezes.
     *
     * @return list<array{city: string, state: string, lat: float, lng: float, spread: float, weight: int, zip: string, ddd: string}>
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
     * @return array{city: string, state: string, lat: float, lng: float, spread: float, weight: int, zip: string, ddd: string}
     */
    private static function place(string $city, string $state, float $lat, float $lng, float $spread, int $weight, string $zip, string $ddd): array
    {
        return compact('city', 'state', 'lat', 'lng', 'spread', 'weight', 'zip', 'ddd');
    }
}
