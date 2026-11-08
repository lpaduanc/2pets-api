<?php

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentOperation;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalEmissionMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `fiscal_documents` deixa de ser só "documento de venda" — spec docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §2:
 *
 * - `sale_id` nulo: a devolução de COMPRA não tem venda. A devolução de venda mantém o
 *   `sale_id` (e `sale_return_id`); `Sale::fiscalDocuments()` filtra `operation = sale`.
 * - `number` vira `bigint`: a numeração passa a ser nossa (`fiscal_number_sequences`) e as
 *   lacunas/inutilização comparam número. Nenhum driver gravava número até aqui (todos nulos).
 * - status `contingency` no CHECK (mesmo padrão de `2026_09_27_100002_expand_invoices_status_check`).
 * - único parcial (dono, ambiente, tipo, série, número): a mesma numeração nunca sai duas vezes.
 */
return new class extends Migration
{
    private const STATUS_CHECK = 'fiscal_documents_status_check';

    private const LEGACY_STATUSES = ['pending', 'processing', 'authorized', 'rejected', 'cancelled', 'denied'];

    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('sale_id')->nullable()->change();
            $table->string('operation', 20)->default(FiscalDocumentOperation::SALE->value)->after('sale_id');
            $table->string('direction', 3)->default(FiscalDocumentDirection::OUT->value)->after('operation');
            $table->foreignId('sale_return_id')->nullable()->after('direction')->constrained()->nullOnDelete();
            $table->foreignId('purchase_return_id')->nullable()->after('sale_return_id')->constrained()->nullOnDelete();
            $table->foreignId('referenced_document_id')->nullable()->after('purchase_return_id')->constrained('fiscal_documents')->nullOnDelete();
            $table->char('referenced_access_key', 44)->nullable()->after('referenced_document_id');
            $table->string('environment', 15)->nullable()->after('kind');
            $table->string('emission_mode', 20)->default(FiscalEmissionMode::NORMAL->value)->after('environment');
            $table->string('contingency_reason')->nullable()->after('emission_mode');
            $table->string('protocol')->nullable()->after('provider_id');
            $table->string('cancellation_protocol')->nullable()->after('protocol');
            $table->string('cancellation_reason', 500)->nullable()->after('cancellation_protocol');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_reason');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('last_error', 500)->nullable();

            $table->index(['operation', 'status']);
            $table->index('sale_return_id');
            $table->index('purchase_return_id');
            $table->index(['status', 'next_retry_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE fiscal_documents ALTER COLUMN number TYPE bigint USING number::bigint');
        DB::statement('ALTER TABLE fiscal_documents DROP CONSTRAINT IF EXISTS '.self::STATUS_CHECK);
        DB::statement($this->check(self::STATUS_CHECK, 'status', FiscalDocumentStatus::values()));
        DB::statement($this->check('fiscal_documents_operation_check', 'operation', FiscalDocumentOperation::values()));
        DB::statement($this->check('fiscal_documents_direction_check', 'direction', FiscalDocumentDirection::values()));
        DB::statement($this->check('fiscal_documents_emission_mode_check', 'emission_mode', FiscalEmissionMode::values()));
        DB::statement('CREATE UNIQUE INDEX fiscal_documents_number_per_organization ON fiscal_documents (organization_id, environment, kind, series, number) WHERE number IS NOT NULL AND organization_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX fiscal_documents_number_per_professional ON fiscal_documents (professional_id, environment, kind, series, number) WHERE number IS NOT NULL AND organization_id IS NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS fiscal_documents_number_per_organization');
            DB::statement('DROP INDEX IF EXISTS fiscal_documents_number_per_professional');
            foreach (['operation', 'direction', 'emission_mode'] as $column) {
                DB::statement("ALTER TABLE fiscal_documents DROP CONSTRAINT IF EXISTS fiscal_documents_{$column}_check");
            }
            // Nenhuma linha `contingency` sobrevive ao CHECK antigo: volta a pendente (vai precisar
            // ser reemitida pelo fluxo antigo, que não conhece contingência).
            DB::update("UPDATE fiscal_documents SET status = 'pending' WHERE status = 'contingency'");
            DB::statement('ALTER TABLE fiscal_documents DROP CONSTRAINT IF EXISTS '.self::STATUS_CHECK);
            DB::statement($this->check(self::STATUS_CHECK, 'status', self::LEGACY_STATUSES));
            DB::statement('ALTER TABLE fiscal_documents ALTER COLUMN number TYPE varchar(255) USING number::varchar');
        }

        // Documento sem venda (devolução de compra) não cabe no `sale_id NOT NULL` antigo.
        DB::table('fiscal_documents')->whereNull('sale_id')->delete();

        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropIndex(['operation', 'status']);
            $table->dropIndex(['sale_return_id']);
            $table->dropIndex(['purchase_return_id']);
            $table->dropIndex(['status', 'next_retry_at']);
            $table->dropConstrainedForeignId('referenced_document_id');
            $table->dropConstrainedForeignId('purchase_return_id');
            $table->dropConstrainedForeignId('sale_return_id');
            $table->dropColumn([
                'operation', 'direction', 'referenced_access_key', 'environment', 'emission_mode',
                'contingency_reason', 'protocol', 'cancellation_protocol', 'cancellation_reason',
                'cancelled_at', 'attempts', 'next_retry_at', 'last_error',
            ]);
            $table->unsignedBigInteger('sale_id')->nullable(false)->change();
        });
    }

    /** @param  list<string>  $values */
    private function check(string $name, string $column, array $values): string
    {
        $quoted = implode(', ', array_map(static fn (string $value): string => DB::getPdo()->quote($value), $values));

        return "ALTER TABLE fiscal_documents ADD CONSTRAINT {$name} CHECK ({$column}::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
