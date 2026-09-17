<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.2.
 *
 * Um agendamento passa a poder ter vários serviços (consulta + vacina + banho, por
 * exemplo) — `appointments.service_id` é uma FK única e não comporta isso.
 * `appointments.service_id` fica DEPRECADA, não removida (pode haver linha antiga
 * apontando pra ela); leitores novos usam esta pivô.
 *
 * `unit_price` é SNAPSHOT do preço no momento do agendamento (invariante 12 do
 * contrato): se o catálogo subir de preço amanhã, o que já foi combinado com o cliente
 * não muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->timestamps();
            $table->softDeletes();

            $table->index('appointment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_services');
    }
};
