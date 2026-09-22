<?php

use App\Services\Search\FuzzyMatchExpressionBuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 7 do fluxo de agendamento: "a busca leva em conta profissionais que estão em
 * clínicas?" — não, hoje não. Uma clínica com um cardiologista na equipe nunca aparecia
 * buscando "cardiologia", porque a busca só lê `professionals.specialties` da PRÓPRIA
 * linha do estabelecimento.
 *
 * ── Abordagem escolhida (medida, não por preferência — ver relato da tarefa) ──────────
 * Espelhar as especialidades da equipe BOOKÁVEL e ATIVA na linha do `Professional` do
 * DONO da organização (`professionals.team_specialties`), mantido por observer
 * (`App\Observers\Organization\*`), e ampliar o MESMO predicado de especialidade que já
 * existe para checar as DUAS colunas (`specialties` OR `team_specialties`) — zero mudança
 * de forma na subquery que já é rápida (`ProfessionalSearchService::
 * applyProfessionalFilters()`, documentada em 137ms vs 42,8s). A alternativa (`EXISTS`
 * correlacionado contra `organization_members JOIN professionals` dentro da mesma
 * subquery) foi PROTOTIPADA e MEDIDA — descartada por ser mais lenta e por reintroduzir
 * exatamente a forma de junção que already causou a regressão de 42,8s documentada.
 *
 * `team_specialties` é TEXT com JSON dentro, mesmo formato de `specialties` — não
 * normalizado na escrita (a normalização acontece na CONSULTA, mesma decisão já registrada
 * no docblock de `ProfessionalAttributeFilter`).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table): void {
            $table->text('team_specialties')->nullable()->after('specialties');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $function = FuzzyMatchExpressionBuilder::IMMUTABLE_UNACCENT_FUNCTION;

        DB::statement(<<<SQL
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_professionals_team_specialties_unaccent_trgm
            ON professionals USING GIN ({$function}(team_specialties) gin_trgm_ops)
            WHERE team_specialties IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_professionals_team_specialties_unaccent_trgm');
        }

        Schema::table('professionals', function (Blueprint $table): void {
            $table->dropColumn('team_specialties');
        });
    }
};
