<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Trava para a armadilha documentada em
 * `2026_09_06_000001_add_search_performance_indexes_to_users_table`: o predicado dos índices
 * parciais de visibilidade tem que continuar batendo com o WHERE que a busca realmente emite.
 *
 * Quando os dois divergem o Postgres NÃO reclama — ele só deixa de casar o índice parcial e a
 * busca volta a varrer ~200 mil linhas em vez de ~35 mil. Sem este teste, a degradação só
 * apareceria como "a busca ficou lenta" semanas depois, sem ligação óbvia com o commit que a
 * causou.
 *
 * A verificação é estrutural (compara filtros), não de plano de execução: `EXPLAIN` em base de
 * teste vazia sempre escolhe Seq Scan, então asserção sobre o plano seria inútil aqui.
 */
class ProfessionalVisibilityIndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Os dois índices parciais criados pela migration de performance.
     *
     * @return list<string>
     */
    private const VISIBILITY_INDEXES = [
        'idx_users_visible_professional_location',
        'idx_users_visible_professional_name',
    ];

    /**
     * Filtros que o scope aplica, na forma `coluna => valor`.
     *
     * @return array<string, mixed>
     */
    private function scopeFilters(): array
    {
        $wheres = User::query()->visibleProfessional()->getQuery()->wheres;

        $filters = [];

        foreach ($wheres as $where) {
            if (($where['type'] ?? null) === 'Basic') {
                $filters[$where['column']] = $where['value'];
            }
        }

        return $filters;
    }

    public function test_every_scope_filter_appears_in_the_partial_index_predicate(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Índice parcial só existe no PostgreSQL.');
        }

        foreach (self::VISIBILITY_INDEXES as $indexName) {
            $definition = DB::table('pg_indexes')
                ->where('indexname', $indexName)
                ->value('indexdef');

            $this->assertNotNull(
                $definition,
                "O índice {$indexName} sumiu. Se foi renomeado, atualize este teste E confirme que ".
                'a migration recriou o predicado — sem ele a busca degrada em silêncio.'
            );

            foreach ($this->scopeFilters() as $column => $value) {
                $this->assertStringContainsString(
                    $column,
                    $definition,
                    "O scope `visibleProfessional` filtra por `{$column}`, mas o predicado de ".
                    "{$indexName} não menciona essa coluna. O índice parcial deixou de casar com a ".
                    'busca: crie migration recriando o índice com o predicado novo.'
                );

                $this->assertStringContainsString(
                    $this->asSqlLiteral($value),
                    $definition,
                    "O scope filtra `{$column}` por um valor que não aparece no predicado de ".
                    "{$indexName}."
                );
            }
        }
    }

    /**
     * O predicado do índice também não pode ser MAIS restritivo que o scope: se ele exigir uma
     * condição que a busca não aplica, o Postgres não consegue provar a implicação e descarta o
     * índice — mesma degradação silenciosa, causa oposta.
     */
    public function test_index_predicate_has_no_condition_beyond_the_scope_and_soft_delete(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Índice parcial só existe no PostgreSQL.');
        }

        $definition = DB::table('pg_indexes')
            ->where('indexname', self::VISIBILITY_INDEXES[0])
            ->value('indexdef');

        $predicate = substr($definition, (int) strpos($definition, 'WHERE'));

        // `deleted_at` vem do SoftDeletes do model, não do scope — é aplicado pelo global scope
        // do Eloquent e por isso é esperado no predicado sem estar em `scopeFilters()`.
        $expectedColumns = [...array_keys($this->scopeFilters()), 'deleted_at'];

        foreach (['role', 'profile_completed', 'registration_status', 'is_suspended', 'deleted_at'] as $column) {
            if (! str_contains($predicate, $column)) {
                continue;
            }

            $this->assertContains(
                $column,
                $expectedColumns,
                "O predicado do índice exige `{$column}`, que a busca não filtra. O Postgres não ".
                'consegue provar a implicação e vai ignorar o índice parcial.'
            );
        }
    }

    public function test_scope_still_filters_the_four_visibility_columns(): void
    {
        $this->assertSame(
            [
                'role' => 'professional',
                'profile_completed' => true,
                'registration_status' => 'approved',
                'is_suspended' => false,
            ],
            $this->scopeFilters(),
            'Mudar a regra de visibilidade pública exige migration recriando os índices parciais.'
        );
    }

    /**
     * `deactivated_at` é filtrado por `whereNull()`, não por `where(coluna, valor)` — não
     * aparece em `scopeFilters()` (que só captura wheres do tipo `Basic`), então precisa de
     * uma checagem própria em vez de cair no loop genérico acima. Ver
     * `2026_09_20_100002_add_deactivated_at_to_visible_professional_indexes`.
     */
    public function test_deactivated_professionals_are_excluded_from_the_partial_index_predicate(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Índice parcial só existe no PostgreSQL.');
        }

        $wheres = User::query()->visibleProfessional()->getQuery()->wheres;
        $hasDeactivatedNullFilter = collect($wheres)->contains(
            fn (array $where): bool => ($where['type'] ?? null) === 'Null'
                && ($where['column'] ?? null) === 'deactivated_at'
        );

        $this->assertTrue(
            $hasDeactivatedNullFilter,
            'O scope `visibleProfessional` precisa excluir contas desativadas (`whereNull(\'deactivated_at\')`).'
        );

        foreach (self::VISIBILITY_INDEXES as $indexName) {
            $definition = DB::table('pg_indexes')->where('indexname', $indexName)->value('indexdef');

            $this->assertStringContainsString(
                'deactivated_at IS NULL',
                $definition,
                "O índice {$indexName} não exclui contas desativadas — um profissional que se ".
                'autodesativou continuaria reservável na busca.'
            );
        }
    }

    private function asSqlLiteral(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
