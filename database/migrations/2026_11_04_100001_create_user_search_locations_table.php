<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Última localização que o usuário logado CONFIRMOU para buscar profissionais — o padrão da
 * próxima visita, em qualquer aparelho (antes vivia só no localStorage, por 24 h).
 *
 * Uma linha por usuário, sobrescrita a cada confirmação. Coordenada com 6 casas no tipo mas
 * gravada arredondada em 3 (~110 m) por `SearchLocationService`: é a posição do tutor, e a
 * busca não precisa de mais que isso (LGPD, minimização).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_search_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('label')->nullable();
            $table->string('zip_code', 8)->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_search_locations');
    }
};
