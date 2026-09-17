<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §6.
 *
 * "Uma fatura por agendamento" hoje é só garantia de APLICAÇÃO
 * (`Invoice::where('appointment_id', ...)->first()` antes de criar, em
 * `AppointmentInvoiceService`). Uma internação de vários dias multiplica a chance de duas
 * requisições concorrentes (dois membros da equipe lançando charge ao mesmo tempo)
 * caírem no mesmo `first() === null` antes de qualquer uma das duas ter inserido — a
 * corrida cria duas `Invoice` para o mesmo agendamento.
 *
 * Índice PARCIAL (`WHERE deleted_at IS NULL`), não um `UNIQUE` de coluna simples: fatura é
 * soft-delete (`invoices.deleted_at`), e um `UNIQUE` comum contaria uma linha apagada como
 * ocupando o `appointment_id` para sempre. `appointment_id` nulo (fatura manual) nunca
 * conflita — `UNIQUE`/índice parcial do Postgres já trata múltiplos `NULL` como distintos.
 *
 * Substitui `invoices_appointment_id_index` (`2026_09_28_100002`) — o novo índice único
 * também serve como índice de busca, não precisa dos dois.
 */
return new class extends Migration
{
    private const OLD_INDEX = 'invoices_appointment_id_index';

    private const UNIQUE_INDEX = 'invoices_appointment_id_unique';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::OLD_INDEX);
        DB::statement('CREATE UNIQUE INDEX '.self::UNIQUE_INDEX.' ON invoices (appointment_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::UNIQUE_INDEX);
        DB::statement('CREATE INDEX '.self::OLD_INDEX.' ON invoices (appointment_id)');
    }
};
