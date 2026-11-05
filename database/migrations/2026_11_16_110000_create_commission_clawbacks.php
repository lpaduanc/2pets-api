<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desconto de comissão estornada (docs/gap-petflow/PLANO.md, 2.3). O fechamento é imutável
 * (regra 4 do doc 09): quando o estorno de um recebimento tira a comissão de um item já fechado,
 * nasce um `commission_clawbacks` (o valor a recuperar do profissional), e os PRÓXIMOS
 * fechamentos dele descontam esse valor em `commission_settlement_deductions` até zerar — nunca
 * deixando o fechamento negativo. Pendente = valor − Σ descontos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_clawbacks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->nullOnDelete();
            // `organization_members.id`: o vínculo já é de UMA organização.
            $table->foreignId('staff_id')->constrained('organization_members');
            $table->foreignId('commission_settlement_item_id')->unique()->constrained();
            $table->foreignId('sale_item_id')->constrained();
            $table->decimal('amount', 14, 2);
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['staff_id', 'id']);
        });

        Schema::create('commission_settlement_deductions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commission_settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commission_clawback_id')->constrained();
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->unique(['commission_settlement_id', 'commission_clawback_id'], 'settlement_clawback_unique');
            $table->index('commission_clawback_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_settlement_deductions');
        Schema::dropIfExists('commission_clawbacks');
    }
};
