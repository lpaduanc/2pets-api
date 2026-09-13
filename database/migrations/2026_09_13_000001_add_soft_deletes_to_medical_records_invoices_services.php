<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `medical_records` e `invoices` são documento clínico e fiscal — apagar de vez viola a
     * regra 5 do CLAUDE.md ("soft delete em tudo") e, no caso do prontuário, tem implicação de
     * guarda regulatória. `services` entra pela mesma regra (item de catálogo do profissional,
     * referenciado por `appointments.service_id`).
     *
     * `inventories` tem a mesma lacuna, mas é domínio de outro agente — não mexida aqui.
     */
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
