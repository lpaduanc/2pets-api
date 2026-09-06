<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cria a tabela `notifications` do Laravel.
 *
 * A tabela nunca existiu neste projeto, apesar de tres pontos do codigo a
 * consultarem — `NotificationController` (via trait Notifiable do User),
 * `DashboardController::...` e `LgpdController::...`. O resultado era
 * `GET /api/notifications` retornando 500 com
 * `SQLSTATE[42P01]: relation "notifications" does not exist`.
 *
 * Os indices NAO sao os do stub padrao do Laravel. O stub cria apenas
 * `morphs('notifiable')`, que resolve o WHERE mas deixa o `ORDER BY created_at
 * DESC` — presente em TODA consulta de notificacao do projeto — como um sort
 * separado. Numa tabela que chega a milhoes de linhas isso e a diferenca entre
 * ler 20 linhas do indice e ordenar o historico inteiro do usuario em memoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            // jsonb, nao text (stub padrao) nem json: a tabela e nova, entao nao
            // ha custo de reescrita, e jsonb e o unico dos tres que e indexavel.
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'pgsql') {
            // Fora do PostgreSQL (sqlite dos testes), cai no indice composto
            // simples: nem indice parcial nem ordenacao por coluna sao
            // expressaveis de forma portavel pelo Blueprint.
            Schema::table('notifications', function (Blueprint $table) {
                $table->index(['notifiable_type', 'notifiable_id']);
            });

            return;
        }

        // Serve `$user->notifications()` e a listagem do dashboard:
        // WHERE notifiable_type = ? AND notifiable_id = ? ORDER BY created_at DESC
        // Com created_at DESC no proprio indice, a paginacao le direto do indice
        // e o planner descarta o no de Sort.
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_notifications_notifiable_created
            ON notifications (notifiable_type, notifiable_id, created_at DESC)
        ');

        // Serve `$user->unreadNotifications()`, que adiciona `read_at IS NULL`.
        // Indice parcial porque a proporcao de nao-lidas e pequena e cai com o
        // tempo: o indice fica uma ordem de grandeza menor que o completo e
        // cabe em cache.
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_notifications_unread
            ON notifications (notifiable_type, notifiable_id, created_at DESC)
            WHERE read_at IS NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
