<?php

namespace Database\Seeders\Dataset;

use App\Enums\SizeCategory;
use Illuminate\Support\Str;

/**
 * Um pet coerente: a raça pertence à espécie e o peso decorre do porte da raça.
 *
 * O porte NÃO é sorteado à parte. `breeds.size_category` já diz o porte de cada raça, e é
 * dele que sai a faixa de peso — é assim que se evita o Yorkshire de 32 kg. Para as espécies
 * cujo catálogo não traz porte (ave, réptil, roedor), o porte cai em `mini`, que é a leitura
 * correta para todas elas no contexto do produto.
 */
final class PetRowBuilder
{
    /**
     * @param  array<string, list<array{id: int, name: string, size_category: ?string}>>  $breedsBySpecies
     * @return array<string, mixed>
     */
    public static function build(int $tutorId, array $breedsBySpecies): array
    {
        $species = self::pickSpecies(array_keys($breedsBySpecies));
        $breed = DatasetRandom::pick($breedsBySpecies[$species]);
        $size = SizeCategory::tryFrom((string) $breed['size_category']) ?? SizeCategory::MINI;
        $now = now();

        return [
            'user_id' => $tutorId,
            'public_id' => (string) Str::uuid(),
            'name' => DatasetRandom::pick(BusinessNamePools::PET_NAMES),
            'species' => $species,
            'breed' => $breed['name'],
            'breed_id' => $breed['id'],
            'size' => $size->value,
            'weight' => self::weightFor($size),
            'gender' => DatasetRandom::chance(2) ? 'male' : 'female',
            'birth_date' => now()->subMonths(mt_rand(3, 168))->toDateString(),
            'color' => DatasetRandom::pick(BusinessNamePools::COAT_COLORS),
            'neutered' => DatasetRandom::chance(2),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Distribuição de mercado, não uniforme: cão e gato são a esmagadora maioria dos pets
     * domésticos no Brasil. Uma base com 20% de répteis mentiria sobre o volume de cada
     * busca por espécie.
     *
     * @param  list<string>  $available
     */
    private static function pickSpecies(array $available): string
    {
        $weighted = [...array_fill(0, 6, 'dog'), ...array_fill(0, 3, 'cat'), 'bird', 'rodent', 'reptile'];
        $candidates = array_values(array_filter($weighted, static fn (string $s): bool => in_array($s, $available, true)));

        return $candidates === [] ? DatasetRandom::pick($available) : DatasetRandom::pick($candidates);
    }

    /** Faixa de peso (kg) de cada porte, segundo a taxonomia do próprio produto. */
    private static function weightFor(SizeCategory $size): string
    {
        [$min, $max] = match ($size) {
            SizeCategory::MINI => [1, 6],
            SizeCategory::SMALL => [6, 12],
            SizeCategory::MEDIUM => [12, 25],
            SizeCategory::LARGE => [25, 45],
            SizeCategory::GIANT => [45, 80],
        };

        return number_format(mt_rand($min * 10, $max * 10) / 10, 2, '.', '');
    }
}
