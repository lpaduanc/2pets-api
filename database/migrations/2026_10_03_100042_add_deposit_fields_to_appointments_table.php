<?php

use App\Enums\DepositStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6 do fluxo de agendamento: `deposit_amount`/`deposit_status` gravados no PRÓPRIO
 * agendamento (não numa fatura — a fatura só nasce no início do atendimento). Todo
 * agendamento nasce `none`: sinal é opcional e desligado por padrão, e o caminho sem sinal
 * não pode mudar em nada (`DEFAULT 'none'` garante isso para toda linha existente e para
 * toda linha nova sem sinal aplicável).
 */
return new class extends Migration
{
    private const CONSTRAINT = 'appointments_deposit_status_check';

    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->decimal('deposit_amount', 10, 2)->nullable()->after('price');
            $table->string('deposit_status', 20)->default(DepositStatus::NONE->value)->after('deposit_amount');

            $table->index(['professional_id', 'deposit_status']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $quoted = implode(', ', array_map(
            static fn (string $status): string => DB::getPdo()->quote($status),
            DepositStatus::values(),
        ));

        DB::statement(
            'ALTER TABLE appointments ADD CONSTRAINT '.self::CONSTRAINT
            ." CHECK (deposit_status::text = ANY (ARRAY[{$quoted}]::text[]))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        }

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropIndex(['professional_id', 'deposit_status']);
            $table->dropColumn(['deposit_amount', 'deposit_status']);
        });
    }
};
