<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `App\Models\Company::$fillable` e o `RegistrationCompletionController`/
 * `RegistrationDraftController` ja escrevem `cnpj`, `contact_name`,
 * `contact_position`, `website`, `employee_count`, `benefit_type` e `notes`
 * desde que esses controllers foram criados — mas a migration original de
 * `companies` (2025_11_22_203246) nunca chegou a criar essas colunas. Isso
 * derruba `POST /register/complete-company` com QueryException em produção
 * hoje, e é o mesmo motivo pelo qual o bloco `company` do contrato de
 * `GET/PUT /api/profile` não tinha onde gravar. `contact_person` fica como
 * está (nula, sem leitor no código) — nenhum caller do repo escreve nela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('cnpj')->nullable()->after('company_name');
            $table->string('contact_name')->nullable()->after('cnpj');
            $table->string('contact_position')->nullable()->after('contact_name');
            $table->string('website')->nullable()->after('phone');
            $table->string('employee_count')->nullable()->after('website');
            $table->string('benefit_type')->nullable()->after('employee_count');
            $table->text('notes')->nullable()->after('benefit_type');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'cnpj',
                'contact_name',
                'contact_position',
                'website',
                'employee_count',
                'benefit_type',
                'notes',
            ]);
        });
    }
};
