<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 3 do split Pessoa/Organização: dá às tabelas do grupo COMERCIAL uma referência
 * NULLABLE à organização, sem tocar em nenhum leitor.
 *
 * `professional_id` (`-> users.id`) continua existindo e continua sendo a FK que todo
 * controller/service já lê. `organization_id` nasce populada pelo backfill da migration
 * seguinte, mas sem nenhum código de aplicação apontando para ela ainda — é fundação, não
 * troca de contrato.
 *
 * Grupo CLÍNICO (`medical_records`, `vaccinations`, `prescriptions`, `hospitalizations`,
 * `surgeries`, `exams`) fica de fora de propósito: ali `professional_id` é autoria com
 * responsabilidade de CRMV — sempre uma pessoa física — e não pode ganhar rota alternativa
 * via organização (CNPJ assinando prontuário é exatamente o que as fases anteriores fecharam).
 *
 * `waitlists`, `review_responses` e `payouts` foram achadas por grep além da lista original
 * de 14 tabelas do escopo: mesma semântica comercial (`professional_id` = "com quem o
 * cliente faz negócio" / "quem recebe o repasse"), por isso entraram junto.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = [
        'appointments',
        'services',
        'invoices',
        'inventories',
        'availabilities',
        'blocked_times',
        'waitlists',
        'reviews',
        'review_responses',
        'products',
        'carts',
        'orders',
        'ad_campaigns',
        'commissions',
        'payouts',
        'locations',
        'favorites',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->addOrganizationIdTo($table);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $this->dropOrganizationIdFrom($table);
        }
    }

    private function addOrganizationIdTo(string $table): void
    {
        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->foreignId('organization_id')
                ->nullable()
                ->after('professional_id')
                ->constrained('organizations')
                ->nullOnDelete();

            $blueprint->index('organization_id');
        });
    }

    private function dropOrganizationIdFrom(string $table): void
    {
        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropConstrainedForeignId('organization_id');
        });
    }
};
