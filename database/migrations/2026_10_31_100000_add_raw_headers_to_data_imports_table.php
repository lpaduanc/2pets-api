<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 26 do backlog gap-simplesvet — `raw_headers` guarda TODOS os cabeçalhos do arquivo
 * enviado, na ordem original, distinto de `column_mapping` (só os que o `ColumnMapper`
 * reconheceu por alias). Sem isto o wizard não consegue oferecer, na tela de mapeamento
 * manual, uma coluna cujo cabeçalho não bateu com nenhum alias conhecido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_imports', function (Blueprint $table): void {
            $table->json('raw_headers')->nullable()->after('column_mapping');
        });
    }

    public function down(): void
    {
        Schema::table('data_imports', function (Blueprint $table): void {
            $table->dropColumn('raw_headers');
        });
    }
};
