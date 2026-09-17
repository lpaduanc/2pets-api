<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §3.
 *
 * `medical_record_id`: rastreabilidade direta fatura → ato clínico, sem depender de
 * `appointment_id` (nulo em faturas manuais/produto).
 *
 * `payment_channel`: distingue "passou pelo gateway do 2pets" (`platform_gateway`) de
 * "profissional declara ter recebido por fora" (`manual_offline`) — sempre gravado
 * explicitamente no momento do pagamento, nunca inferido (`App\Enums\PaymentChannel`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('medical_record_id')
                ->nullable()
                ->after('appointment_id')
                ->constrained()
                ->nullOnDelete();

            $table->string('payment_channel', 20)->nullable()->after('payment_date');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('payment_channel');
            $table->dropConstrainedForeignId('medical_record_id');
        });
    }
};
