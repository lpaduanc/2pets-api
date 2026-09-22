<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `scheduled_notifications` NUNCA teve migration: `App\Console\Commands\SendScheduledNotifications`
 * (`notifications:send`, comando agendado) explode em `sendQueuedNotifications()` com
 * `SQLSTATE[42P01]: relation "scheduled_notifications" does not exist` — e como o método
 * roda DEPOIS de `sendAppointmentReminders()` sem `try/catch`, o comando inteiro falha e os
 * lembretes de consulta (24h/2h) também param de sair. Mais urgente que `push_subscriptions`
 * por isso: aqui o dano já está em produção a cada execução agendada.
 *
 * Schema derivado de `App\Models\ScheduledNotification::$fillable`/`$casts` + do único
 * consumidor real (`SendScheduledNotifications::sendQueuedNotifications()`):
 * - `user_id` — `->with('user')` e o destinatário de `notificationService->sendNotification()`.
 * - `notification_type` — string (o comando faz `NotificationType::from($notification->notification_type)`,
 *   ou seja, é o `->value` do enum, não a label).
 * - `data` — jsonb; o comando lê `data['title']`, `data['body']`, `data['extra']`.
 * - `scheduled_for` — filtro `<= now()`.
 * - `sent` (boolean, default false) + `sent_at` (nullable) — marcado via `->update(['sent' => true, 'sent_at' => now()])`.
 *
 * Sem soft delete: mesmo padrão já usado em `payment_webhooks` e em `notifications` deste
 * projeto (tabelas de fila/log de processamento — o registro "morre" naturalmente ao ser
 * marcado `sent`/`processed`, não faz sentido reter histórico de exclusão de um item de
 * fila). Fica com timestamps normais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('notification_type');
            $table->jsonb('data');
            $table->timestamp('scheduled_for');
            $table->boolean('sent')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'pgsql') {
            // sqlite (suíte de testes): índice composto simples — índice parcial não é
            // portável pelo Blueprint.
            Schema::table('scheduled_notifications', function (Blueprint $table): void {
                $table->index(['sent', 'scheduled_for']);
            });

            return;
        }

        // Serve exatamente `WHERE scheduled_for <= ? AND sent = false` — a query que
        // `sendQueuedNotifications()` roda a cada execução agendada. Índice parcial: a
        // fração `sent = false` fica pequena e estável ao longo do tempo (itens processados
        // nunca voltam a ser lidos por este filtro), mesmo que a tabela cresça sem limite.
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_scheduled_notifications_pending
            ON scheduled_notifications (scheduled_for)
            WHERE sent = false
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_notifications');
    }
};
