<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suporte ao índice para `SendScheduledNotifications::sendAppointmentReminders()`, agora
 * que `notifications:send` roda a cada 10 minutos para sempre (`routes/console.php`).
 *
 * A tabela já tem `index('status')` sozinho e `index(['professional_id', 'appointment_date'])`/
 * `index(['client_id', 'appointment_date'])` — nenhum serve
 * `WHERE status = 'scheduled' AND appointment_date BETWEEN ? AND ?`: com `status` sozinho,
 * o planner acha TODAS as linhas `scheduled` (fração que só cresce por dia, nunca some — um
 * agendamento fica `scheduled` até virar `in_progress`/`cancelled`) e só então filtra a
 * data; com os índices compostos por profissional/cliente, a busca teria que varrer um por
 * um, sem ordem de conjunto.
 *
 * Índice parcial (só `status = 'scheduled'`, o único valor que esta query usa) sobre
 * `appointment_date`: mesmo padrão já usado em `scheduled_notifications` (`WHERE sent =
 * false`) e no índice de não-lidas de `notifications` — o subconjunto "agendado, ainda não
 * aconteceu" fica pequeno e estável em relação ao histórico total, então o índice não
 * cresce sem limite mesmo com a tabela crescendo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            // sqlite (suíte de testes): índice composto simples — índice parcial não é
            // portável pelo Blueprint.
            Schema::table('appointments', function (Blueprint $table): void {
                $table->index(['status', 'appointment_date'], 'idx_appointments_status_date');
            });

            return;
        }

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_appointments_scheduled_date
            ON appointments (appointment_date)
            WHERE status = 'scheduled'
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('appointments', function (Blueprint $table): void {
                $table->dropIndex('idx_appointments_status_date');
            });

            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_appointments_scheduled_date');
    }
};
