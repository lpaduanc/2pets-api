<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 do plano de otimizacao — dominio "agenda" (appointments).
 *
 * `availabilities` e `blocked_times` ja tem cobertura de indice completa para todo FK
 * filtrado em codigo (availabilities_professional_id_day_of_week_is_active_index,
 * blocked_times_professional_id_start_datetime_end_datetime_index, e os *_location_id_index
 * de ambas) — nao ha nada a criar nesses dois nesta fase.
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

        $this->createAppointmentsPetIdIndex();
        $this->createAppointmentsServiceIdIndex();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_appointments_service_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_appointments_pet_id');
    }

    /**
     * appointments.pet_id nao tinha indice (so appointments_client_id_appointment_date_index,
     * appointments_professional_id_appointment_date_index, appointments_location_id_index e
     * appointments_assigned_staff_id_index). Tabela com 2M linhas no benchmark.
     *
     * Query nomeada: app/Http/Controllers/Api/PetVetAccessController.php:352-357 —
     * `Appointment::whereIn('pet_id', $petIds)->where('professional_id', $vet->id)
     * ->where('status', 'completed')` para calcular a ultima visita de cada pet do vet.
     * Roda toda vez que um veterinario abre a lista de pacientes.
     *
     * Parcial (WHERE deleted_at IS NULL): `appointments` tem SoftDeletes e a query acima usa
     * o Eloquent padrao (sem withTrashed()) — o unico withTrashed() do projeto inteiro e em
     * PetAuditController, e so atinge Vaccination/PetDeworming/PetMedication/Surgery/Exam/
     * Hospitalization por pet_id, nunca Appointment. Confirmado por grep em app/ inteiro.
     */
    private function createAppointmentsPetIdIndex(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_appointments_pet_id
            ON appointments (pet_id)
            WHERE deleted_at IS NULL
            SQL);
    }

    /**
     * appointments.service_id nao tinha indice. Nao ha WHERE explicito por service_id em
     * nenhum controller/service hoje (grep confirmado) — a justificativa aqui e o criterio (b):
     * a FK e `ON DELETE SET NULL` (database/migrations/2025_12_27_194000_create_booking_system_tables.php:40),
     * e um professional apagando um servico (Service model nao tem soft delete) dispara, no
     * nivel do Postgres, uma varredura de appointments (2M linhas) por service_id para colocar
     * NULL nas linhas afetadas. Sem indice, esse UPDATE implicito e sequencial na tabela inteira.
     */
    private function createAppointmentsServiceIdIndex(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_appointments_service_id
            ON appointments (service_id)
            WHERE deleted_at IS NULL
            SQL);
    }
};
