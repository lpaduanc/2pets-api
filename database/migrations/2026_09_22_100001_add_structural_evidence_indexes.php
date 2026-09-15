<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices da camada 2 da hierarquia de relevância (`RelevanceLayer::STRUCTURAL`): a oferta
 * declarada no cadastro e o equipamento.
 *
 * `professionals.services_offered` e `professionals.equipment` guardam array JSON de slugs
 * (`["bath","haircut"]`, `["xray_machine"]`) numa coluna `text`/`json`. A busca passou a
 * consultá-los com contenção jsonb (`@>`) para responder "quem declarou que faz banho?" sem
 * depender de texto — os slugs são em inglês e o termo buscado é português, então trigrama
 * não resolveria.
 *
 * `jsonb_ops` (o padrão) e não `jsonb_path_ops`: o segundo é menor e mais rápido, mas serve
 * só `@>`. O padrão serve `@>`, `?`, `?|` e `?&` — e deixar a porta aberta para `?|` importa
 * porque é a forma natural de "qualquer um destes itens"; hoje ela é inacessível só porque
 * `?` colide com o placeholder do PDO, o que é limitação do cliente, não do banco.
 *
 * `CONCURRENTLY` não roda dentro de transação — daí `$withinTransaction = false`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_professionals_services_offered_jsonb
            ON professionals USING GIN ((services_offered::jsonb))
            WHERE services_offered IS NOT NULL AND deleted_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_professionals_equipment_jsonb
            ON professionals USING GIN ((equipment::jsonb))
            WHERE equipment IS NOT NULL AND deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_professionals_equipment_jsonb');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_professionals_services_offered_jsonb');
    }
};
