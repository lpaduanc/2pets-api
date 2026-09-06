<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 do plano de otimizacao — dominio "social/messaging" (reviews, favorites, messages,
 * conversations, notifications).
 *
 * favorites e notifications ja tem cobertura completa para todo uso real encontrado por grep
 * (favorites_user_id_professional_id_unique, idx_notifications_notifiable_created,
 * idx_notifications_unread) — nao sao tocadas aqui (favorites.user_id_index, redundante, e
 * descartado na migration 6).
 *
 * `CONCURRENTLY` nao pode rodar dentro de uma transacao.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->createConversationsParticipantTwoIndex();
        $this->createMessageAttachmentsIndex();
        $this->createReviewPhotosIndex();
        $this->createReviewsAppointmentIdIndex();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_reviews_appointment_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_review_photos_review_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_message_attachments_message_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_conversations_participant_two_id');
    }

    /**
     * conversations.participant_two_id so estava coberto como 2a coluna do unique
     * (participant_one_id, participant_two_id) — inutil para filtrar so por essa coluna.
     * Query nomeada: app/Services/Chat/MessagingService.php:57-58 e 103-104 —
     * `where('participant_one_id', $userId)->orWhere('participant_two_id', $userId)`, a
     * query de "minhas conversas". O primeiro lado do OR usa o indice do unique; sem este
     * indice o segundo lado forcava seq scan nas 100k linhas de conversations TODA VEZ que
     * essa query roda (achado real desta auditoria, nao estava no plano original).
     */
    private function createConversationsParticipantTwoIndex(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_conversations_participant_two_id ON conversations (participant_two_id)');
    }

    /**
     * message_attachments.message_id nao tinha indice. Query nomeada:
     * app/Services/Chat/MessagingService.php:87 — `->with(['sender', 'attachments'])` na
     * listagem de mensagens de uma conversa (messages tem 1M linhas no benchmark, e toda
     * pagina de chat eager-loada os anexos).
     */
    private function createMessageAttachmentsIndex(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_message_attachments_message_id ON message_attachments (message_id)');
    }

    /**
     * review_photos.review_id nao tinha indice (so a PK). Query nomeada:
     * app/Services/Review/ReviewService.php:59,109,137 — tres eager loads de `photos`
     * (`Review::with([..., 'photos'])`), incluindo a listagem publica de avaliacoes do
     * profissional (reviews: 400k linhas no benchmark).
     */
    private function createReviewPhotosIndex(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_review_photos_review_id ON review_photos (review_id)');
    }

    /**
     * reviews.appointment_id so estava coberto como 2a coluna do unique
     * (professional_id, appointment_id) — inutil para filtrar so por essa coluna. Query
     * nomeada: app/Services/Review/ReviewService.php:149 —
     * `Review::where('appointment_id', $appointmentId)->first()`, checagem de "ja existe
     * review para este agendamento" antes de liberar o formulario de avaliacao.
     *
     * Parcial (WHERE deleted_at IS NULL): reviews tem SoftDeletes e a query acima e Eloquent
     * padrao, sem withTrashed — o unico withTrashed() do projeto e em PetAuditController e
     * nao toca Review.
     *
     * NOTA DE DIVERGENCIA (achado nesta auditoria, fora do escopo de correcao desta fase —
     * "nao reescreva nenhuma query"): `User::reviews()` (app/Models/User.php:207-210) e
     * `hasMany(Review::class)` SEM especificar a FK, entao o Eloquent assume `user_id`. A
     * tabela `reviews` nao tem coluna `user_id` (tem `client_id`) — `$user->reviews()->get()`,
     * chamado em app/Http/Controllers/Api/LgpdController.php:54 (exportacao de dados LGPD),
     * quebra com "column reviews.user_id does not exist" sempre que executado. Por isso NAO
     * foi usado como justificativa de indice para reviews.client_id (nenhuma query real e
     * exercitavel hoje por essa coluna) — reportado ao usuario para correcao em outra fase.
     */
    private function createReviewsAppointmentIdIndex(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reviews_appointment_id
            ON reviews (appointment_id)
            WHERE deleted_at IS NULL
            SQL);
    }
};
