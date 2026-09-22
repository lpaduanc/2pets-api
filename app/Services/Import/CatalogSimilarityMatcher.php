<?php

namespace App\Services\Import;

use Illuminate\Support\Str;

/**
 * Casamento por similaridade de texto, em PHP (item 26 do backlog gap-simplesvet) — usado por
 * `BreedMatcher`/`CoatCatalogResolver` para achar "Labrador" quando a planilha manda
 * "labrador retriver".
 *
 * Deliberadamente NÃO usa `pg_trgm`/`similarity()` do Postgres: a spec original sugeria
 * trigram de banco, mas isso acopla o casamento de catálogo (poucas dezenas de linhas por
 * espécie/dono) a uma extensão específica do driver, só para ganhar velocidade que este volume
 * não precisa. `similar_text()` nativo do PHP resolve com portabilidade total entre os drivers
 * de teste e produção deste repo.
 */
final class CatalogSimilarityMatcher
{
    private const MINIMUM_SCORE_PERCENT = 70.0;

    /**
     * @param  list<string>  $candidates
     */
    public function bestMatch(string $needle, array $candidates): ?string
    {
        $normalizedNeedle = $this->normalize($needle);
        if ($normalizedNeedle === '') {
            return null;
        }

        return $this->highestScoringCandidate($normalizedNeedle, $candidates);
    }

    /**
     * @param  list<string>  $candidates
     */
    private function highestScoringCandidate(string $normalizedNeedle, array $candidates): ?string
    {
        $bestCandidate = null;
        $bestScore = 0.0;

        foreach ($candidates as $candidate) {
            $score = $this->scoreAgainst($normalizedNeedle, $candidate);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCandidate = $candidate;
            }
        }

        return $bestScore >= self::MINIMUM_SCORE_PERCENT ? $bestCandidate : null;
    }

    private function scoreAgainst(string $normalizedNeedle, string $candidate): float
    {
        similar_text($normalizedNeedle, $this->normalize($candidate), $percent);

        return $percent;
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->lower()->ascii()->trim()->toString();
    }
}
