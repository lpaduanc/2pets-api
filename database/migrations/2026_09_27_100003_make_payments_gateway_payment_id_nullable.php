<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §3.
 *
 * Pagamento manual (dinheiro, PIX direto declarado pelo profissional) não tem id de
 * gateway. Com a coluna nullable, o UNIQUE do Postgres continua íntegro: múltiplos `NULL`
 * não colidem entre si.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway_payment_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway_payment_id')->nullable(false)->change();
        });
    }
};
