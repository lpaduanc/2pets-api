<?php

use App\Enums\PaymentPurpose;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6 do fluxo de agendamento: `Payment` passa a representar também o SINAL de um
 * agendamento, que não tem fatura nenhuma no momento em que é cobrado (a fatura só nasce
 * no início do atendimento, `ConsultationController::start`). Por isso `invoice_id` deixa
 * de ser obrigatório, e ganha um `appointment_id` irmão — cada `Payment` de sinal aponta
 * para o agendamento, nunca para uma fatura.
 *
 * `payments_purpose_check` (criado em `2026_09_30_100003_add_purpose_to_payments_table`)
 * precisa ser recriado para aceitar `deposit` — mesmo padrão DROP/ADD CONSTRAINT daquela
 * migration.
 */
return new class extends Migration
{
    private const PURPOSE_CONSTRAINT = 'payments_purpose_check';

    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('appointment_id')->nullable()->after('invoice_id')->constrained()->nullOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE payments ALTER COLUMN invoice_id DROP NOT NULL');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS '.self::PURPOSE_CONSTRAINT);
        DB::statement($this->purposeCheckStatement());
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS '.self::PURPOSE_CONSTRAINT);
            // Sinal (`invoice_id IS NULL`) precisa sair ANTES de a coluna voltar a ser
            // obrigatória, senão o `SET NOT NULL` falha com linha existente nula.
            DB::statement("DELETE FROM payments WHERE purpose = 'deposit'");
            DB::statement('ALTER TABLE payments ALTER COLUMN invoice_id SET NOT NULL');
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('appointment_id');
        });
    }

    private function purposeCheckStatement(): string
    {
        $quoted = implode(', ', array_map(
            static fn (string $purpose): string => DB::getPdo()->quote($purpose),
            PaymentPurpose::values(),
        ));

        return 'ALTER TABLE payments ADD CONSTRAINT '.self::PURPOSE_CONSTRAINT
            ." CHECK (purpose::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
