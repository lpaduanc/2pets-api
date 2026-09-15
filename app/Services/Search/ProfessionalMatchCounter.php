<?php

namespace App\Services\Search;

use App\DataTransferObjects\SearchFiltersDTO;
use App\Exceptions\Search\SearchCountTimedOutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Quantos profissionais casam com uma busca — sem materializar id, sem hidratar model e sem
 * paginar.
 *
 * Existe separado de `ProfessionalSearchService` porque contar e listar são trabalhos com
 * exigências opostas: listar precisa da ordem por relevância e dos 500 primeiros ids; contar
 * não precisa de ordem nenhuma e não tolera custo. Quem consome é o "você quis dizer..."
 * (`SearchSuggestionFinder`), que precisa do total de CADA conceito alternativo para ordenar
 * as sugestões.
 *
 * ── Por que `getCountForPagination()` e não a contagem de `search()` ──────────────────
 * Ele descarta `columns`, `orders`, `limit` e `offset` antes de contar, então o
 * `top-N heapsort` de 500 linhas por relevância desaparece. Medido (SP, 25 km, 7.001
 * profissionais), mesmo total nos dois caminhos: "cardiologia" 525 ms pelo caminho de ids
 * contra **9 ms** aqui; "hospedagem" 111 ms contra 37 ms.
 *
 * Termo LARGO não melhora ("veterinario": 200 ms contra 162 ms) e nem com teto artificial
 * (145 ms com `LIMIT 500` por dentro): ali o custo é ACHAR as linhas pelo predicado fuzzy, e
 * nenhuma contagem escapa disso. É essa medição que obriga as duas proteções abaixo.
 *
 * ── Proteção 1: `statement_timeout` ───────────────────────────────────────────────────
 * O orçamento de latência de quem chama é em PHP e só consegue decidir se vale a pena
 * começar a PRÓXIMA consulta — ele não interrompe a que já está no banco. Sem teto no banco,
 * um único termo largo levava 500 ms sozinho e o orçamento virava recomendação.
 *
 * ── Proteção 2: cache, inclusive do FRACASSO ──────────────────────────────────────────
 * Sem gravar o estouro, os termos largos pagariam os 80 ms do timeout em TODA requisição,
 * para sempre — medido, 85 ms fixos na família de prefixos "hosp*" mesmo com todo o resto
 * quente. Com o marcador, a segunda tentativa custa a leitura de uma chave.
 */
final class ProfessionalMatchCounter
{
    /**
     * Teto de tempo de BANCO por contagem. Medido: o que estoura este valor é conceito largo
     * ("clinica veterinaria", "veterinario"), que custa de 150 ms a 580 ms sozinho — mais do
     * que a busca que o usuário de fato pediu.
     */
    private const TIMEOUT_MILLISECONDS = 80;

    /** `query_canceled` — o que o Postgres devolve quando `statement_timeout` corta. */
    private const SQLSTATE_QUERY_CANCELED = '57014';

    /**
     * Valor gravado no cache para dizer "esta não cabe no orçamento". Precisa ser um total
     * impossível porque o cache não distingue chave ausente de valor `null`.
     */
    private const UNCOUNTABLE_MARKER = -1;

    public function __construct(
        private readonly ProfessionalSearchService $searchService,
        private readonly ProfessionalSearchCache $professionalSearchCache,
    ) {}

    /**
     * @throws SearchCountTimedOutException quando a contagem não cabe no orçamento de banco
     */
    public function count(SearchFiltersDTO $filters): int
    {
        if ($filters->availableNow) {
            return $this->countMatching($filters);
        }

        $cached = $this->professionalSearchCache->cachedCount($filters);

        return $cached === null ? $this->countAndCache($filters) : $this->requireCountable($cached);
    }

    /**
     * O total já cacheado, ou `null` se ainda não foi calculado — nunca dispara consulta.
     * É o que permite a quem ordena sugestões colher o barato antes de gastar orçamento.
     * O marcador de "não contável" também devolve `null`: para quem só espia, ele é
     * indistinguível de ausência, e quem insistir recebe a exceção por `count()`.
     */
    public function cachedCount(SearchFiltersDTO $filters): ?int
    {
        if ($filters->availableNow) {
            return null;
        }

        $cached = $this->professionalSearchCache->cachedCount($filters);

        return $cached === self::UNCOUNTABLE_MARKER ? null : $cached;
    }

    private function countAndCache(SearchFiltersDTO $filters): int
    {
        try {
            $total = $this->countMatching($filters);
        } catch (SearchCountTimedOutException $exception) {
            $this->professionalSearchCache->putCount($filters, self::UNCOUNTABLE_MARKER);

            throw $exception;
        }

        $this->professionalSearchCache->putCount($filters, $total);

        return $total;
    }

    private function requireCountable(int $cached): int
    {
        if ($cached === self::UNCOUNTABLE_MARKER) {
            throw new SearchCountTimedOutException('Contagem de sugestao de busca marcada como nao contavel.');
        }

        return $cached;
    }

    /**
     * `SET LOCAL` (e não `SET`) porque a transação devolve o valor anterior ao terminar,
     * commit ou rollback: nenhuma configuração vaza para o resto da requisição.
     */
    private function countMatching(SearchFiltersDTO $filters): int
    {
        try {
            return DB::transaction(fn (): int => $this->countWithStatementTimeout($filters));
        } catch (QueryException $exception) {
            throw $this->asTimeoutOrRethrow($exception, $filters);
        }
    }

    private function countWithStatementTimeout(SearchFiltersDTO $filters): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            return $this->countQuery($filters);
        }

        DB::statement('SET LOCAL statement_timeout = '.self::TIMEOUT_MILLISECONDS);
        $total = $this->countQuery($filters);

        // O `RESET` explícito cobre o caso em que JÁ existe uma transação aberta por fora:
        // aí `DB::transaction()` vira um SAVEPOINT, e LIBERAR um savepoint NÃO desfaz o
        // `SET LOCAL` — ele valeria até o fim da transação externa. É o que acontece sob
        // `RefreshDatabase`, onde a suíte inteira roda dentro de uma transação. No estouro
        // não é preciso resetar: o `ROLLBACK TO SAVEPOINT` desfaz o `SET LOCAL` junto.
        DB::statement('RESET statement_timeout');

        return $total;
    }

    private function countQuery(SearchFiltersDTO $filters): int
    {
        return $this->searchService->filteredQuery($filters, 'users.id')->toBase()->getCountForPagination();
    }

    /**
     * SQLSTATE 57014 (`query_canceled`) é o que o Postgres devolve quando o
     * `statement_timeout` corta a consulta. Qualquer outro erro é erro de verdade e sobe
     * inteiro — engolir aqui esconderia uma query quebrada atrás de uma sugestão ausente.
     */
    private function asTimeoutOrRethrow(QueryException $exception, SearchFiltersDTO $filters): Throwable
    {
        if ($exception->getCode() !== self::SQLSTATE_QUERY_CANCELED) {
            return $exception;
        }

        Log::warning('Contagem de sugestao de busca excedeu o tempo limite.', [
            'search_query' => $filters->searchQuery,
            'timeout_ms' => self::TIMEOUT_MILLISECONDS,
        ]);

        return new SearchCountTimedOutException('Contagem de sugestao de busca excedeu o tempo limite.');
    }
}
