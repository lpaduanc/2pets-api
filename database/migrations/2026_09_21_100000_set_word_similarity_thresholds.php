<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Thresholds das GUCs que os operadores `%>` e `%>>` usam — a busca de profissionais migrou
 * de `%` (similaridade da string INTEIRA) para similaridade de PALAVRA, que é o que permite
 * achar "cardiologia" dentro de "Dra. Carolina Mendes, especialista em Cardiologia"
 * (medido: 0,289 com `similarity()` contra 1,000 com `word_similarity()`).
 *
 * Estes valores são PISO, não o critério final. `ProfessionalTextSearchQuery` sempre soma ao
 * operador um `word_similarity(...) >= ?` com o limiar do contexto (campo + tamanho do
 * termo), justamente para a precisão da busca não ficar amarrada a uma configuração de
 * servidor: mexer nestas GUCs por qualquer outro motivo não deve mudar quem aparece na
 * busca. O papel delas aqui é só deixar o índice GIN trigram fazer a triagem barata antes.
 *
 * Os limiares REAIS da aplicação (`App\Enums\Search\SearchField::threshold()`) são 0,60 no
 * modo palavra e 0,50 no estrito; 0,75/0,60 em campo de texto livre. Os pisos abaixo são
 * EXATAMENTE o menor limiar de cada modo — nem mais, nem menos, e isso é deliberado:
 *
 * - Mais alto que o menor limiar da aplicação faria o índice descartar linhas que a
 *   aplicação ainda aceitaria: a busca ficaria mais restritiva do que o código diz.
 * - Mais BAIXO custa latência, e muito. Medido com EXPLAIN (ANALYZE, BUFFERS): com o piso em
 *   0,5, o `Bitmap Index Scan` de `idx_services_name_unaccent_trgm` devolvia 162.015 das
 *   180.015 linhas de `services` para o termo "banho e tosa" — 90% da tabela passando pelo
 *   recheck caro de `word_similarity` no heap, 4,2 s só nesse nó. O quanto o índice consegue
 *   podar depende diretamente desta GUC: ela decide quantos trigramas em comum são
 *   obrigatórios.
 *
 * Campo de texto livre (limiar 0,75) continua sendo refinado pela aplicação depois do
 * índice, porque um piso de 0,75 seria alto demais para os demais campos.
 *
 * `pg_trgm.similarity_threshold` (0.2, migration 2026_09_06_000010) NÃO é tocado aqui: ele
 * continua servindo `%`, ainda usado pela busca de prescrições e de pacientes.
 *
 * Mesmo padrão da migration de 2026_09_06_000010: `ALTER DATABASE` para durar, mais um `SET`
 * de sessão porque `ALTER DATABASE` só vale para conexões novas — e a conexão que roda esta
 * migration é a mesma que o `RefreshDatabase` da suíte mantém aberta o processo inteiro.
 */
return new class extends Migration
{
    private const WORD_SIMILARITY_THRESHOLD = 0.6;

    private const STRICT_WORD_SIMILARITY_THRESHOLD = 0.5;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->applyThreshold('pg_trgm.word_similarity_threshold', self::WORD_SIMILARITY_THRESHOLD);
        $this->applyThreshold('pg_trgm.strict_word_similarity_threshold', self::STRICT_WORD_SIMILARITY_THRESHOLD);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->resetThreshold('pg_trgm.strict_word_similarity_threshold');
        $this->resetThreshold('pg_trgm.word_similarity_threshold');
    }

    private function applyThreshold(string $setting, float $value): void
    {
        $database = DB::getDatabaseName();

        DB::statement("ALTER DATABASE \"{$database}\" SET {$setting} = {$value}");
        DB::statement("SET {$setting} = {$value}");
    }

    private function resetThreshold(string $setting): void
    {
        $database = DB::getDatabaseName();

        DB::statement("ALTER DATABASE \"{$database}\" RESET {$setting}");
        DB::statement("RESET {$setting}");
    }
};
