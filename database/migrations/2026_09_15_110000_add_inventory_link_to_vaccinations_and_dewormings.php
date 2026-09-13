<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo OPCIONAL entre o ato clínico (vacina/vermífugo) e o item de estoque debitado —
 * docs/vinculo-estoque-aplicacao-clinica.md itens 1-4 e 7. `inventory_id` nulo é o caso normal
 * (tutor auto-relato, campanha pública, vet volante sem controle de estoque na plataforma), não
 * uma exceção — por isso `nullOnDelete()`: um item de estoque removido nunca pode apagar o
 * histórico clínico que ele ajudou a registrar.
 *
 * `vaccinations.expiry_date` fecha um gap de conformidade real e independente de estoque: a
 * Resolução CFMV nº 1.321/2020 (alterada pela 1.653/2025) exige lote + fabricante + dose +
 * validade no registro do ato vacinal — `manufacturer`/`batch_number`/`dose_number` já
 * existiam, só a validade do lote aplicado faltava.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaccinations', function (Blueprint $table) {
            $table->foreignId('inventory_id')->nullable()->after('professional_id')
                ->constrained()->nullOnDelete();
            $table->date('expiry_date')->nullable()->after('batch_number');
        });

        Schema::table('pet_dewormings', function (Blueprint $table) {
            $table->foreignId('inventory_id')->nullable()->after('veterinarian_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vaccinations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_id');
            $table->dropColumn('expiry_date');
        });

        Schema::table('pet_dewormings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_id');
        });
    }
};
