<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Achado de carona ao expor `invoice_id` em `AppointmentResource`/`MedicalRecordResource`
 * (contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.5): FK do
 * Postgres não cria índice sozinha (mesma armadilha já documentada na migration
 * `2026_09_28_100001`), e `invoices.appointment_id` já era filtrado no hot path
 * (`AppointmentInvoiceService::ensurePendingInvoice()`/`syncItems()`, chamados em TODO
 * `start()`/`/charges`) mesmo antes desta rodada — a exposição nos dois resources só
 * multiplicou quantas vezes por request essa busca roda (agora também via
 * `Appointment::invoice()`/`MedicalRecord::invoice()` eager-loaded).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->index('appointment_id');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['appointment_id']);
        });
    }
};
