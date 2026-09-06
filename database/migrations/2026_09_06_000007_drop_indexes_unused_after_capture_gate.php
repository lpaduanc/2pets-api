<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 do plano de otimizacao — portao de saida objetivo.
 *
 * Regra do plano: "todo indice que voce criou e ficou com idx_scan = 0 [apos rodar o
 * capture.ps1 completo] deve ser removido. Isso e objetivo e nao admite discussao."
 *
 * Os 9 indices abaixo, criados nas migrations 2026_09_06_000002/000003/000004/000005,
 * continuaram com idx_scan = 0 mesmo depois de: (a) rodar storage/perf/capture.ps1
 * label "fase4-indices", e (b) exercitar manualmente por HTTP autenticado todo endpoint
 * relevante nao coberto pelo capture (professional/my-patients, invoices, inventory,
 * hospitalizations, surgeries, messages/conversations, exams/pet/{id}), e (c) confirmar via
 * `EXPLAIN ANALYZE` com a query real citada no docblock original de cada indice.
 *
 * O item (c) mostrou que a causa NAO e indice mal desenhado: as tabelas abaixo tem 0 a 6
 * linhas no banco de dev hoje (exams, surgeries, hospitalizations, pet_vet_accesses,
 * invoices, inventories, order_items, message_attachments, review_photos nao fizeram parte
 * do volume gerado pelo BenchmarkSeeder da Fase 2 — so users/professionals/pets/appointments/
 * notifications/messages/favorites/vaccinations/prescriptions/medical_records/reviews/
 * services/availabilities/conversations foram populadas em volume). Com 0-6 linhas, o
 * planner do Postgres PREFERE corretamente Seq Scan a Index Scan mesmo para a query exata
 * do predicado (confirmado por EXPLAIN — ver relato da fase) — nao ha volume nenhum que
 * justifique pagar o custo de I/O de um index scan numa tabela de 1 pagina.
 *
 * Aplicando a regra do plano de forma literal e objetiva: removidos aqui. Ficam FORA desta
 * migration (portanto mantidos) os que mostraram idx_scan > 0 apos as mesmas verificacoes:
 * idx_appointments_pet_id, idx_appointments_service_id, idx_conversations_participant_two_id,
 * idx_medical_records_appointment_id, idx_prescriptions_appointment_id,
 * idx_prescriptions_medical_record_id, idx_vaccinations_appointment_id,
 * idx_vaccinations_professional_id, idx_reviews_appointment_id — todos com tabela de
 * volume real (500k-2M linhas) onde o planner escolheu Index Scan de fato.
 *
 * RECOMENDACAO (nao executada aqui — fora do escopo de "so indices" desta fase): quando o
 * BenchmarkSeeder for estendido para cobrir exams/surgeries/hospitalizations/
 * pet_vet_accesses/invoices/inventories/order_items/message_attachments/review_photos (ou
 * quando o volume real de producao chegar la), recriar os 9 indices abaixo com a MESMA
 * definicao e a MESMA justificativa de query citada nas migrations 000002/000003/000004/
 * 000005 (ainda presentes no historico do repositorio) — nao redesenhar do zero.
 *
 * `CONCURRENTLY` nao pode rodar dentro de uma transacao.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * @var list<string>
     */
    private const DROPPED_INDEXES = [
        'idx_exams_pet_id',
        'idx_surgeries_pet_id',
        'idx_hospitalizations_pet_id',
        'idx_surgeries_professional_id',
        'idx_hospitalizations_professional_id',
        'idx_pet_vet_accesses_pet_veterinarian',
        'idx_invoices_professional_id',
        'idx_inventories_professional_id',
        'idx_order_items_order_id',
        'idx_message_attachments_message_id',
        'idx_review_photos_review_id',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::DROPPED_INDEXES as $indexName) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$indexName}");
        }
    }

    /**
     * O rollback recria com a mesma definicao das migrations originais — nao e "desfazer o
     * portao", e sim permitir reverter esta migration especifica sem perder a definicao.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_exams_pet_id ON exams (pet_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_surgeries_pet_id ON surgeries (pet_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_hospitalizations_pet_id ON hospitalizations (pet_id)');
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_surgeries_professional_id
            ON surgeries (professional_id)
            WHERE deleted_at IS NULL
            SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_hospitalizations_professional_id
            ON hospitalizations (professional_id)
            WHERE deleted_at IS NULL
            SQL);
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_pet_vet_accesses_pet_veterinarian ON pet_vet_accesses (pet_id, veterinarian_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_invoices_professional_id ON invoices (professional_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_inventories_professional_id ON inventories (professional_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_order_items_order_id ON order_items (order_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_message_attachments_message_id ON message_attachments (message_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_review_photos_review_id ON review_photos (review_id)');
    }
};
