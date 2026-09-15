<?php

namespace Tests\Unit;

use App\Support\Pagination\ReachableLengthAwarePaginator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * O paginador que separa "quantos casaram" de "quantos dá para entregar".
 *
 * ── A incoerência que ele corrige ─────────────────────────────────────────────────────
 * `ProfessionalSearchService` materializa no máximo 500 ids por busca, mas o
 * `LengthAwarePaginator` calcula `lastPage` a partir do total. Com `per_page=12` e 2.991
 * resultados a API anunciava `last_page: 250` e devolvia página vazia a partir da 42ª — sem
 * erro, HTTP 200, `data: []`.
 *
 * O teste usa os números REAIS medidos na base de desenvolvimento, e não múltiplos redondos,
 * porque foram eles que expuseram o problema.
 */
class ReachableLengthAwarePaginatorTest extends TestCase
{
    private const MEASURED_TOTAL = 2991;

    private const MEASURED_REACHABLE = 500;

    private const PER_PAGE = 12;

    private function paginator(int $total, int $reachable, int $perPage = self::PER_PAGE): ReachableLengthAwarePaginator
    {
        return (new ReachableLengthAwarePaginator(new Collection, $total, $perPage, 1))
            ->reachableUpTo($reachable);
    }

    public function test_the_last_page_reflects_what_can_be_delivered(): void
    {
        $paginator = $this->paginator(self::MEASURED_TOTAL, self::MEASURED_REACHABLE);

        $this->assertSame(42, $paginator->lastPage());
    }

    /**
     * O total tem que continuar real: "2.991 profissionais encontrados" é verdade e é o
     * número que o tutor quer ver. Baixá-lo para 500 trocaria uma mentira por outra.
     */
    public function test_the_total_stays_the_real_number_of_matches(): void
    {
        $paginator = $this->paginator(self::MEASURED_TOTAL, self::MEASURED_REACHABLE);

        $this->assertSame(self::MEASURED_TOTAL, $paginator->total());
        $this->assertSame(self::MEASURED_REACHABLE, $paginator->reachableTotal());
    }

    /**
     * `last_page` não é um número solto — `links.last`, `next_page_url` e a lista de links
     * numerados derivam dele. É por isso que a correção mora no paginador e não no `meta`.
     */
    public function test_everything_derived_from_the_last_page_follows_it(): void
    {
        $paginator = $this->paginator(self::MEASURED_TOTAL, self::MEASURED_REACHABLE);

        $this->assertStringContainsString('page=42', (string) $paginator->url($paginator->lastPage()));
        $this->assertStringNotContainsString('page=250', (string) $paginator->toArray()['last_page_url']);
    }

    public function test_the_last_reachable_page_reports_no_further_pages(): void
    {
        $paginator = (new ReachableLengthAwarePaginator(new Collection, self::MEASURED_TOTAL, self::PER_PAGE, 42))
            ->reachableUpTo(self::MEASURED_REACHABLE);

        $this->assertFalse($paginator->hasMorePages());
    }

    public function test_a_result_set_below_the_ceiling_is_not_truncated_at_all(): void
    {
        $paginator = $this->paginator(37, 37);

        $this->assertSame(4, $paginator->lastPage());
        $this->assertSame(37, $paginator->total());
    }

    /**
     * Busca vazia tem uma página, não zero — é o mesmo comportamento do paginador nativo, e
     * um `last_page: 0` faria qualquer cliente que compara `current_page >= last_page` achar
     * que já passou do fim.
     */
    public function test_an_empty_result_set_still_has_one_page(): void
    {
        $this->assertSame(1, $this->paginator(0, 0)->lastPage());
    }
}
