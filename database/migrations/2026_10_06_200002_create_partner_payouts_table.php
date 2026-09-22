<?php

use App\Enums\PartnerPayoutStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md, regra de
 * negócio 7 — repasse a parceiro terceiro (vet volante/diarista sem vínculo CLT), registrado e
 * conciliado manualmente, sem regra automática nem `commission_rules`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('partner_user_id')->constrained('users');

            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();

            // Referência polimórfica opcional — pode apontar a um conjunto de sale_items,
            // appointments, ou nada e ser só um valor combinado manualmente.
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->string('status', 20)->default(PartnerPayoutStatus::PENDING->value);
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users');

            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'partner_user_id', 'status']);
            $table->index(['professional_id', 'partner_user_id', 'status']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::getConnection()->statement(sprintf(
            "ALTER TABLE partner_payouts ADD CONSTRAINT partner_payouts_status_check CHECK (status IN ('%s'))",
            implode("','", PartnerPayoutStatus::values())
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_payouts');
    }
};
