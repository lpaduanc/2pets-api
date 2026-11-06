<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Metas de venda (docs/gap-petflow/PLANO.md, 2.5): uma por organização + mês + escopo +
 * `scope_id`. `scope_id` é o centro de custo (`cost_center`) ou o vínculo do profissional
 * (`staff`, `organization_members.id`); nulo nos demais. O índice único usa `COALESCE` porque
 * NULL não colide em índice único do Postgres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->char('month', 7);
            $table->string('scope', 20);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'month']);
        });

        DB::statement("ALTER TABLE sales_goals ADD CONSTRAINT sales_goals_scope_check CHECK (scope IN ('total', 'products', 'services', 'cost_center', 'staff'))");
        DB::statement("ALTER TABLE sales_goals ADD CONSTRAINT sales_goals_scope_id_check CHECK ((scope IN ('cost_center', 'staff')) = (scope_id IS NOT NULL))");
        DB::statement("ALTER TABLE sales_goals ADD CONSTRAINT sales_goals_month_check CHECK (month ~ '^[0-9]{4}-(0[1-9]|1[0-2])$')");
        DB::statement('ALTER TABLE sales_goals ADD CONSTRAINT sales_goals_amount_check CHECK (amount >= 0)');
        DB::statement('CREATE UNIQUE INDEX sales_goals_unique_scope ON sales_goals (COALESCE(organization_id, 0), COALESCE(professional_id, 0), month, scope, COALESCE(scope_id, 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_goals');
    }
};
