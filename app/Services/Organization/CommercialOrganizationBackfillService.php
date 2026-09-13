<?php

namespace App\Services\Organization;

use App\Models\OrganizationMember;
use Illuminate\Support\Facades\DB;

/**
 * Item 3 do split Pessoa/Organização: preenche `organization_id` nas tabelas do grupo
 * COMERCIAL a partir do vínculo `owner` em `organization_members` — o dono cujo `user_id`
 * bate com o `professional_id` da linha. Fica `NULL` quando o `professional_id` é vet
 * volante solo (nunca virou dono de organização) — esse é o resultado correto, não uma
 * lacuna a preencher depois.
 *
 * SQL puro (`UPDATE ... FROM`) em vez de hidratar Eloquent: nenhuma das tabelas do grupo
 * comercial usa `LogsActivity`, então não há custo de auditoria por linha a evitar, mas o
 * volume aqui (milhões de linhas em `appointments`) é grande demais para carregar em
 * memória — e a operação é puramente relacional (copiar uma coluna de outra tabela), sem
 * regra de negócio que justifique passar por model.
 *
 * Batelas por faixa de `id`, nunca a tabela inteira numa única instrução: o incidente da
 * Fase 1 (`OrganizationBackfillService`) estourou `max_locks_per_transaction` com
 * SAVEPOINT por linha, mas o mesmo limite também é alcançável aqui por uma via diferente —
 * cada linha atualizada dispara a checagem de integridade referencial de
 * `organization_id`, que prende um lock de linha na organização referenciada até o fim da
 * transação. Uma única instrução cobrindo a tabela inteira poderia reter um lock distinto
 * por organização referenciada (36.646 no ambiente de dev) dentro de uma única transação
 * implícita — perto o bastante do patamar que já derrubou o Postgres uma vez para não
 * arriscar de novo. Cada instrução em lote é sua própria transação implícita (a migration
 * chamadora roda com `$withinTransaction = false`), então o lock nunca escala além do
 * tamanho do lote.
 *
 * Idempotente: o filtro `organization_id IS NULL` faz uma reexecução tocar só o que ainda
 * não foi preenchido, e cada lote já commitado não é revisitado.
 */
final class CommercialOrganizationBackfillService
{
    private const CHUNK_SIZE = 5000;

    /**
     * Grupo COMERCIAL — ver a mesma lista na migration de schema
     * (`2026_09_14_100000_add_organization_id_to_commercial_tables`) para o porquê de cada
     * tabela estar ou não aqui.
     *
     * @var list<string>
     */
    private const TABLES = [
        'appointments',
        'services',
        'invoices',
        'inventories',
        'availabilities',
        'blocked_times',
        'waitlists',
        'reviews',
        'review_responses',
        'products',
        'carts',
        'orders',
        'ad_campaigns',
        'commissions',
        'payouts',
        'locations',
        'favorites',
    ];

    /** Tabela temporária de sessão com o mapa `user_id => organization_id` não ambíguo. */
    private const OWNER_MAP = 'tmp_sole_owner_organization';

    /**
     * @return array<string, int> linhas atualizadas por tabela nesta execução
     */
    public function run(): array
    {
        $this->buildSoleOwnerMap();

        $updatedByTable = [];

        foreach (self::TABLES as $table) {
            $updatedByTable[$table] = $this->backfillTable($table);
        }

        $this->dropSoleOwnerMap();

        return $updatedByTable;
    }

    /**
     * Materializa `user_id => organization_id` APENAS para quem é dono de exatamente UMA
     * organização.
     *
     * Por que o `HAVING COUNT(*) = 1` não é detalhe: casar direto em `organization_members`
     * por `user_id` produz múltiplas linhas quando a pessoa é dona de duas organizações, e o
     * `UPDATE ... FROM` do Postgres resolve isso escolhendo uma linha ARBITRÁRIA, sem erro e
     * sem aviso. O agendamento da Clínica A seria atribuído à Clínica B em silêncio.
     *
     * Não existe resposta correta a inferir nesse caso — o dado legado não registra a qual
     * negócio a linha pertence, só quem é a pessoa. Então a linha fica com `organization_id`
     * nulo e entra no relatório de ambíguos, para tratamento explícito. Preencher errado é
     * pior que não preencher: erra faturamento e agenda sem deixar rastro.
     *
     * O mapa é materializado uma vez por execução em vez de virar subquery repetida em cada
     * lote — são 17 tabelas × N lotes, e reagregar `organization_members` a cada instrução
     * seria trabalho redundante em toda a varredura.
     */
    private function buildSoleOwnerMap(): void
    {
        DB::statement('DROP TABLE IF EXISTS '.self::OWNER_MAP);

        DB::statement(
            'CREATE TEMPORARY TABLE '.self::OWNER_MAP.' AS
             SELECT user_id, MIN(organization_id) AS organization_id
             FROM organization_members
             WHERE role = ?
             GROUP BY user_id
             HAVING COUNT(*) = 1',
            [OrganizationMember::ROLE_OWNER]
        );

        DB::statement('CREATE INDEX ON '.self::OWNER_MAP.' (user_id)');
    }

    private function dropSoleOwnerMap(): void
    {
        DB::statement('DROP TABLE IF EXISTS '.self::OWNER_MAP);
    }

    /**
     * Donos de mais de uma organização, cujas linhas comerciais ficam deliberadamente sem
     * `organization_id`. Exposto para a migration registrar em log e para teste.
     */
    public function ambiguousOwnerCount(): int
    {
        // `select('user_id')` explícito: agrupar sem projetar coluna nenhuma faz o builder emitir
        // `select *`, que o Postgres rejeita — toda coluna projetada precisa estar no GROUP BY ou
        // dentro de uma agregação.
        return DB::table('organization_members')
            ->select('user_id')
            ->where('role', OrganizationMember::ROLE_OWNER)
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
    }

    private function backfillTable(string $table): int
    {
        $minId = DB::table($table)->min('id');
        $maxId = DB::table($table)->max('id');

        if ($minId === null) {
            return 0;
        }

        // O driver do Postgres devolve bigint como string; sem o cast, a aritmética do laço e a
        // comparação com `min()` ficam sujeitas a coerção de tipo em vez de comparação numérica.
        $minId = (int) $minId;
        $maxId = (int) $maxId;

        $updated = 0;

        for ($startId = $minId; $startId <= $maxId; $startId += self::CHUNK_SIZE) {
            $endId = min($startId + self::CHUNK_SIZE - 1, $maxId);
            $updated += $this->backfillChunk($table, $startId, $endId);
        }

        return $updated;
    }

    /**
     * O nome da tabela vem exclusivamente de `self::TABLES`, uma constante interna fechada
     * — nunca de input do usuário — por isso a interpolação direta no SQL é segura.
     */
    private function backfillChunk(string $table, int $startId, int $endId): int
    {
        $ownerMap = self::OWNER_MAP;

        return DB::affectingStatement(
            <<<SQL
                UPDATE {$table}
                SET organization_id = {$ownerMap}.organization_id
                FROM {$ownerMap}
                WHERE {$ownerMap}.user_id = {$table}.professional_id
                  AND {$table}.organization_id IS NULL
                  AND {$table}.id BETWEEN ? AND ?
                SQL,
            [$startId, $endId]
        );
    }
}
