<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cria a tabela `notification_preferences` — achado colateral da Fase 6 do
 * plano de otimizacao (Haversine -> PostGIS), nao coberto por nenhuma
 * migration existente apesar do model `App\Models\NotificationPreference`
 * e de TRES pontos do codigo a consultarem:
 * `NotificationService::getEnabledChannels()` (chamado por TODO
 * `sendNotification()`, ou seja, por toda notificacao do projeto) e
 * `NotificationController::getPreferences()`/`updatePreferences()`.
 *
 * Sintoma antes desta migration: qualquer chamada a
 * `NotificationService::sendNotification()` lancava
 * `QueryException: relation "notification_preferences" does not exist` —
 * nao era um problema de performance, a funcionalidade inteira de
 * notificacao (lembrete de consulta, pagamento, pet perdido...) estava
 * quebrada em qualquer ambiente com o schema atual. Descoberto ao escrever
 * o job `NotifyNearbyUsersOfLostPetAlert` desta Fase, que depende de
 * `NotificationService`.
 *
 * Colunas e chave unica seguem exatamente o uso real em
 * `NotificationController::updatePreferences()` (linhas 96-104):
 * `updateOrCreate(['user_id', 'notification_type', 'channel'], ['enabled'])`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('notification_type');
            $table->string('channel');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'notification_type', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
