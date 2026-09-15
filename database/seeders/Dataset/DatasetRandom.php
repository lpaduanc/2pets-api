<?php

namespace Database\Seeders\Dataset;

/**
 * Sorteio DETERMINÍSTICO do dataset de desenvolvimento.
 *
 * `seed()` fixa o Mersenne Twister do PHP, então rodar o seeder duas vezes produz exatamente
 * o mesmo cadastro. Isso não é capricho: o teste de relevância afirma "busca por banho e tosa
 * só retorna quem oferece banho e tosa", e um dataset que muda a cada execução transforma
 * qualquer falha em "será que foi o sorteio?". Com semente fixa, uma diferença no resultado é
 * sempre uma diferença no código.
 */
final class DatasetRandom
{
    public static function seed(int $seed): void
    {
        mt_srand($seed);
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    public static function pick(array $values): mixed
    {
        return $values[mt_rand(0, count($values) - 1)];
    }

    /**
     * `$max` é aparado pelo tamanho da lista — pedir 5 de uma lista de 2 devolve os 2, em vez
     * de estourar. Acontece de verdade: `laboratory` tem só duas categorias de serviço.
     *
     * @template T
     *
     * @param  list<T>  $values
     * @return list<T>
     */
    public static function pickMany(array $values, int $min, int $max): array
    {
        $total = count($values);

        if ($total === 0) {
            return [];
        }

        $wanted = mt_rand(min($min, $total), min($max, $total));

        if ($wanted < 1) {
            return [];
        }

        $keys = (array) array_rand($values, $wanted);

        return array_values(array_map(static fn (int $key): mixed => $values[$key], $keys));
    }

    /** Verdadeiro uma vez a cada `$oneIn` chamadas, em média. */
    public static function chance(int $oneIn): bool
    {
        return mt_rand(1, $oneIn) === 1;
    }

    /** Valor monetário com duas casas, dentro da faixa. */
    public static function money(int $min, int $max): string
    {
        return number_format(mt_rand($min * 100, $max * 100) / 100, 2, '.', '');
    }

    /** Deslocamento quase-normal (soma de três uniformes) em torno de zero, em `$spread`. */
    public static function jitter(float $spread): float
    {
        $sum = mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax();

        return ($sum - 1.5) * $spread;
    }
}
