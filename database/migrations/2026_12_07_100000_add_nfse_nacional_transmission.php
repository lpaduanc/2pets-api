<?php

use App\Enums\FiscalDocumentKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NFS-e pelo Sistema Nacional (fase B) — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §4/§5.3.
 *
 * - A DPS é numerada por NÓS (série/número por dono e ambiente), o número da NFS-e é da Sefin:
 *   por isso `dps_series`/`dps_number` separados de `series`/`number` (que guarda o nNFSe).
 * - Códigos de serviço da DPS (cTribNac da Lista Nacional, NBS) no cadastro do serviço e
 *   CONGELADOS no item do documento, como o NCM do produto: editar o serviço depois não muda uma
 *   NFS-e pendente.
 * - `fiscal_number_sequences` passa a aceitar `nfse`.
 */
return new class extends Migration
{
    private const KIND_CHECK = 'fiscal_number_sequences_kind_check';

    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('nfse_series', 3)->default('1');
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->string('national_tax_code', 6)->nullable()->after('lc116_code');
            $table->string('nbs_code', 9)->nullable()->after('national_tax_code');
        });

        Schema::table('fiscal_document_items', function (Blueprint $table): void {
            $table->string('national_tax_code', 6)->nullable();
            $table->string('municipal_tax_code', 3)->nullable();
            $table->string('nbs_code', 9)->nullable();
        });

        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->string('dps_series', 5)->nullable();
            $table->unsignedBigInteger('dps_number')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX fiscal_documents_dps_per_organization ON fiscal_documents (organization_id, environment, dps_series, dps_number) WHERE dps_number IS NOT NULL AND organization_id IS NOT NULL AND deleted_at IS NULL');
        $this->replaceKindCheck([FiscalDocumentKind::NFE->value, FiscalDocumentKind::NFCE->value, FiscalDocumentKind::NFSE->value]);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS fiscal_documents_dps_per_organization');
            DB::statement("DELETE FROM fiscal_number_sequences WHERE kind = 'nfse'");
            $this->replaceKindCheck([FiscalDocumentKind::NFE->value, FiscalDocumentKind::NFCE->value]);
        }

        Schema::table('fiscal_documents', fn (Blueprint $table) => $table->dropColumn(['dps_series', 'dps_number']));
        Schema::table('fiscal_document_items', fn (Blueprint $table) => $table->dropColumn(['national_tax_code', 'municipal_tax_code', 'nbs_code']));
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn(['national_tax_code', 'nbs_code']));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('nfse_series'));
    }

    /** @param  list<string>  $kinds */
    private function replaceKindCheck(array $kinds): void
    {
        $quoted = implode(', ', array_map(static fn (string $kind): string => DB::getPdo()->quote($kind), $kinds));
        DB::statement('ALTER TABLE fiscal_number_sequences DROP CONSTRAINT IF EXISTS '.self::KIND_CHECK);
        DB::statement('ALTER TABLE fiscal_number_sequences ADD CONSTRAINT '.self::KIND_CHECK." CHECK (kind::text = ANY (ARRAY[{$quoted}]::text[]))");
    }
};
