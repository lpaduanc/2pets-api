<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Amplia o catálogo de diagnóstico (contrato
 * docs/atendimento-veterinario/05-contrato-consulta-sem-digitacao.md §8): `Pathology` só
 * cobria condição CRÔNICA (as 20 entradas de `PathologySeeder`) — não serve para sugerir
 * diagnóstico de consulta ambulatorial comum (otite, gastroenterite, conjuntivite...).
 *
 * Só a COLUNA e o BACKFILL das linhas já existentes moram aqui — os ~22 diagnósticos agudos
 * novos moram em `PathologySeeder` (mesmo lugar das 20 crônicas), não nesta migration:
 * `pathologies` é dado de referência/catálogo, e este projeto já semeia catálogo via Seeder,
 * nunca via `DB::table()->insert()` dentro de `up()` — inserir linha nova aqui faria a suíte de
 * teste (que roda `RefreshDatabase`, migrations mas NUNCA seeders, em toda classe de teste)
 * ganhar 22 pathologies "de graça" e quebrar qualquer teste que já contava linhas da tabela
 * (`MasterDataTest::test_pathologies_endpoint_returns_data`, por exemplo) — achado ao rodar o
 * teste manualmente por curl/tinker antes de fechar esta fatia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pathologies', function (Blueprint $table) {
            $table->boolean('is_chronic')->nullable()->after('category');
        });

        DB::table('pathologies')->whereNull('is_chronic')->update(['is_chronic' => true]);
    }

    public function down(): void
    {
        Schema::table('pathologies', function (Blueprint $table) {
            $table->dropColumn('is_chronic');
        });
    }
};
