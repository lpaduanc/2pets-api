<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antes do workflow pending→accepted, `granted_at` era marcado na criação.
 * Com pre-aceite, solicitações pendentes ainda não têm granted_at — então o campo vira nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_vet_accesses', function (Blueprint $table) {
            $table->timestamp('granted_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pet_vet_accesses', function (Blueprint $table) {
            $table->timestamp('granted_at')->nullable(false)->change();
        });
    }
};
