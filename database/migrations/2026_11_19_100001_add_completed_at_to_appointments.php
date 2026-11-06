<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data em que o agendamento foi concluído (PLANO 2.6). A comissão `on_execution` nasce nessa data
 * e o fechamento compara a data de corte com ela; `updated_at` não serve, porque muda com
 * qualquer edição depois da conclusão. Gravado por `AppointmentCompletionObserver`.
 *
 * Backfill: agendamento já concluído recebe `updated_at` (a melhor aproximação disponível — não
 * há carimbo da conclusão no histórico da tabela).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->timestamp('completed_at')->nullable()->after('cancelled_at');
        });

        DB::table('appointments')->where('status', 'completed')->update(['completed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('completed_at');
        });
    }
};
