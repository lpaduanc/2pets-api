<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `push_subscriptions` NUNCA teve migration — achado pelo frontend integrando a Fase 4
 * (push real): `app/Models/PushSubscription.php`, `PushNotificationService` e o canal
 * `App\Notifications\Channels\FcmChannel` (Fase 4) todos dependem desta tabela desde que
 * foram escritos, e nenhum deles nunca rodou de verdade em NENHUM ambiente —
 * `NotificationController::registerDevice` respondia 500
 * (`SQLSTATE[42P01]: relation "push_subscriptions" does not exist`) em qualquer tentativa
 * real de registrar um device.
 *
 * Schema derivado do que `PushSubscription::$fillable`/`PushNotificationService` REALMENTE
 * usam (não inventado):
 * - `user_id` — `PushNotificationService::findSubscriptionsFor()` busca por ele; FK +
 *   índice, é a query que o envio de push faz de verdade.
 * - `device_token` — o valor que a Apple/Google/navegador emite. ÚNICO: o mesmo aparelho
 *   nunca pode ter duas linhas (perderia a garantia de "um push por aparelho, não um por
 *   linha duplicada"), e é o que permite `registerDevice()` REASSOCIAR o token a um novo
 *   usuário quando o mesmo aparelho troca de conta (logout de A, login de B no mesmo
 *   celular) em vez de tentar inserir uma segunda linha com token repetido.
 * - `device_type` — `ios|android|web`, mesmo enum já validado em
 *   `NotificationController::registerDevice()`.
 * - `last_used_at` — atualizado a cada `registerDevice()`, nullable.
 * - Soft delete: convenção do projeto ("soft delete em tudo"). Sem isso,
 *   `unregisterDevice()` (hoje um `DELETE` físico) perderia o histórico de quais
 *   aparelhos já pertenceram a um usuário.
 *
 * `device_token` é único só ENTRE LINHAS VIVAS (índice parcial `WHERE deleted_at IS
 * NULL`, mesmo padrão já usado em `sales_org_number_unique`) — um `unique()` comum
 * colidiria para sempre com o registro antigo assim que o aparelho desregistrasse e
 * tentasse registrar de novo (soft delete não libera unicidade normal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_token');
            $table->string('device_type', 20)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX push_subscriptions_device_token_unique ON push_subscriptions (device_token) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
