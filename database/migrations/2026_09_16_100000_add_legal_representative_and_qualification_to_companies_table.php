<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `CompleteProfileCompany.vue` sempre pediu estes 11 campos, mas
 * `CompleteCompanyRegistrationRequest::rules()` nunca os declarava — e
 * `$request->validated()` só devolve chave que está nas rules. Resultado: preenchidos na
 * tela, descartados em silêncio antes de chegar em `RegistrationCompletionService`.
 *
 * Todas as colunas nascem `nullable()` mesmo as que o Form Request torna obrigatórias
 * (`legal_representative_*`, `industry_sector`) — mesma convenção da migration anterior
 * (`2026_09_06_120000`): companies existentes não têm esse dado e não há valor de
 * preenchimento retroativo correto a inventar. A obrigatoriedade vive na validação de
 * cadastro novo, não na constraint do banco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('legal_representative_name')->nullable()->after('contact_position');
            $table->string('legal_representative_cpf', 11)->nullable()->after('legal_representative_name');
            $table->date('legal_representative_birth_date')->nullable()->after('legal_representative_cpf');
            $table->string('legal_representative_phone')->nullable()->after('legal_representative_birth_date');

            $table->string('industry_sector')->nullable()->after('notes');
            $table->boolean('has_pet_policy')->default(false)->after('industry_sector');
            $table->string('estimated_pet_owners')->nullable()->after('has_pet_policy');
            $table->string('preferred_communication')->nullable()->after('estimated_pet_owners');
            $table->string('budget_range')->nullable()->after('preferred_communication');
            $table->date('start_date_preference')->nullable()->after('budget_range');
            $table->json('interested_services')->nullable()->after('start_date_preference');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'legal_representative_name',
                'legal_representative_cpf',
                'legal_representative_birth_date',
                'legal_representative_phone',
                'industry_sector',
                'has_pet_policy',
                'estimated_pet_owners',
                'preferred_communication',
                'budget_range',
                'start_date_preference',
                'interested_services',
            ]);
        });
    }
};
