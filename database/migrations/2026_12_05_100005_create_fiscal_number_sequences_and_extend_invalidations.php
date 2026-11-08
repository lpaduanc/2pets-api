<?php

use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalNumberInvalidationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numeração própria de NF-e/NFC-e e inutilização de faixa — spec docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §3.7. Sem provedor terceiro (decisão do produto), quem numera somos
 * nós: uma linha por (dono, modelo, série, ambiente), travada com `lockForUpdate` na reserva.
 * NFS-e não entra aqui — o número vem do Emissor Nacional.
 *
 * `fiscal_number_invalidations` existia sem uso; ganha ano, ambiente, situação e o retorno do
 * provedor (o pedido pode ser rejeitado pela SEFAZ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('series', 3);
            $table->string('environment', 15);
            // Primeiro número emitido POR ESTE sistema na série: quem vem de outro sistema começa no
            // 152, e de 1 a 151 não são lacunas nossas (foram emitidas lá).
            $table->unsignedBigInteger('start_number')->default(1);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });

        Schema::table('fiscal_number_invalidations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->nullable()->after('kind');
            $table->string('environment', 15)->nullable()->after('year');
            $table->string('status', 15)->default(FiscalNumberInvalidationStatus::PENDING->value)->after('reason');
            $table->string('provider')->nullable()->after('status');
            $table->string('provider_id')->nullable()->after('provider');
            $table->string('rejection_reason')->nullable()->after('provider_id');
            $table->foreignId('user_id')->nullable()->after('professional_id')->constrained('users')->nullOnDelete();

            $table->index(['organization_id', 'kind', 'series']);
            $table->index(['professional_id', 'kind', 'series']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX fiscal_number_sequences_per_organization ON fiscal_number_sequences (organization_id, kind, series, environment) WHERE organization_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX fiscal_number_sequences_per_professional ON fiscal_number_sequences (professional_id, kind, series, environment) WHERE organization_id IS NULL');

        $kinds = implode(', ', array_map(static fn (string $kind): string => DB::getPdo()->quote($kind), [FiscalDocumentKind::NFE->value, FiscalDocumentKind::NFCE->value]));
        DB::statement("ALTER TABLE fiscal_number_sequences ADD CONSTRAINT fiscal_number_sequences_kind_check CHECK (kind::text = ANY (ARRAY[{$kinds}]::text[]))");
        DB::statement("ALTER TABLE fiscal_number_invalidations ADD CONSTRAINT fiscal_number_invalidations_kind_check CHECK (kind::text = ANY (ARRAY[{$kinds}]::text[]))");

        $statuses = implode(', ', array_map(static fn (string $status): string => DB::getPdo()->quote($status), FiscalNumberInvalidationStatus::values()));
        DB::statement("ALTER TABLE fiscal_number_invalidations ADD CONSTRAINT fiscal_number_invalidations_status_check CHECK (status::text = ANY (ARRAY[{$statuses}]::text[]))");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE fiscal_number_invalidations DROP CONSTRAINT IF EXISTS fiscal_number_invalidations_kind_check');
            DB::statement('ALTER TABLE fiscal_number_invalidations DROP CONSTRAINT IF EXISTS fiscal_number_invalidations_status_check');
        }

        Schema::table('fiscal_number_invalidations', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'kind', 'series']);
            $table->dropIndex(['professional_id', 'kind', 'series']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['year', 'environment', 'status', 'provider', 'provider_id', 'rejection_reason']);
        });

        Schema::dropIfExists('fiscal_number_sequences');
    }
};
