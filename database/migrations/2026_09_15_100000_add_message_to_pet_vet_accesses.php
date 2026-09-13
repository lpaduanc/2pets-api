<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `RequestVetAccessRequest` sempre validou `message` (justificativa do vet para o pedido de
 * acesso), mas a coluna nunca existiu — a mensagem era validada e descartada, e o tutor nunca
 * via por que o profissional estava pedindo acesso ao prontuário do pet dele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_vet_accesses', function (Blueprint $table) {
            $table->text('message')->nullable()->after('requested_access_level');
        });
    }

    public function down(): void
    {
        Schema::table('pet_vet_accesses', function (Blueprint $table) {
            $table->dropColumn('message');
        });
    }
};
