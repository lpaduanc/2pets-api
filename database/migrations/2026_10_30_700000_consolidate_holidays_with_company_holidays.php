<?php

use App\Enums\HolidayScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidação pedida pelo coordenador: item 23 (`holidays`/`App\Models\Holiday`, catálogo
 * configurável, consumido pela agenda do item 21) e item 03 (`company_holidays`/
 * `App\Models\CompanyHoliday`, usado por `DueDateService`) modelavam o MESMO conceito —
 * feriado da clínica — em duas tabelas. `company_holidays` nunca chegou a ser usada por
 * ninguém além do `DueDateService` (nada commitado ainda), então a consolidação vai só num
 * sentido: `holidays` ganha os campos que faltavam (`scope`, `uf`, `city_ibge_code`) e
 * `company_holidays` é derrubada.
 *
 * `holidays.organization_id`/`professional_id` são NULLABLE no schema original (item 23) mas
 * a CONVENÇÃO da aplicação (`CatalogController`) sempre preenche um dos dois — exceto para o
 * feriado NACIONAL seedado (`HolidaySeeder`), que é a única linha desta tabela com os dois
 * nulos de propósito (visível para todo mundo, nunca editável pelo catálogo genérico —
 * `CatalogController::index()` inclui essa linha com um `orWhere` dedicado; `update`/`destroy`
 * usam a query escopada normal, que NUNCA alcança organization_id+professional_id nulos, então
 * o feriado nacional já sai protegido contra edição pelo catálogo sem código extra).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table): void {
            $table->string('scope', 20)->default(HolidayScope::COMPANY->value)->after('recurring_annually');
            $table->string('uf', 2)->nullable()->after('scope');
            $table->string('city_ibge_code', 10)->nullable()->after('uf');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(sprintf(
                "ALTER TABLE holidays ADD CONSTRAINT holidays_scope_check CHECK (scope IN ('%s'))",
                implode("','", HolidayScope::values())
            ));
        }

        Schema::dropIfExists('company_holidays');
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table): void {
            $table->dropColumn(['scope', 'uf', 'city_ibge_code']);
        });

        // `company_holidays` não é recriada no rollback: a migration original
        // (`2026_10_25_400000`) já foi removida do repositório junto com esta consolidação.
    }
};
