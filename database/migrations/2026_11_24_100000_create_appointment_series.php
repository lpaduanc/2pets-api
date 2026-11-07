<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recorrência de agendamento (docs/gap-petflow/PLANO.md, 3.1).
 *
 * `appointment_series` guarda a regra e o "molde" de cada ocorrência (cliente, pet, tipo,
 * duração, serviços, pacote). As ocorrências são `appointments` MATERIALIZADOS até 90 dias à
 * frente (`materialized_until`); o comando diário `appointments:extend-series` cria o resto.
 * `skipped_indices`: ocorrências puladas na criação (conflito resolvido com "pular") — o job não
 * as recria. `last_index`: a série foi encerrada ("este e os seguintes") depois deste índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('professional_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('pet_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->foreignId('appointment_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('duration')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->json('services')->nullable();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('sold_package_id')->nullable()->constrained()->nullOnDelete();

            $table->string('freq', 30);
            $table->unsignedSmallInteger('interval')->default(1);
            $table->json('weekdays')->nullable();
            $table->date('until')->nullable();
            $table->unsignedSmallInteger('count')->nullable();
            $table->string('timezone', 40)->default('America/Sao_Paulo');
            $table->date('start_date');
            $table->string('start_time', 5);
            $table->json('skipped_indices')->nullable();
            $table->unsignedSmallInteger('last_index')->nullable();
            $table->date('materialized_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['professional_id', 'last_index']);
        });

        DB::statement("ALTER TABLE appointment_series ADD CONSTRAINT appointment_series_freq_check CHECK (freq IN ('weekly', 'biweekly', 'monthly_by_day', 'monthly_by_weekday'))");
        DB::statement('ALTER TABLE appointment_series ADD CONSTRAINT appointment_series_end_check CHECK ((until IS NULL) <> (count IS NULL))');
        DB::statement('ALTER TABLE appointment_series ADD CONSTRAINT appointment_series_count_check CHECK (count IS NULL OR (count BETWEEN 1 AND 52))');

        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('series_id')->nullable()->constrained('appointment_series')->nullOnDelete();
            $table->unsignedSmallInteger('occurrence_index')->nullable();
            $table->boolean('is_exception')->default(false);
            $table->unique(['series_id', 'occurrence_index']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropUnique(['series_id', 'occurrence_index']);
            $table->dropConstrainedForeignId('series_id');
            $table->dropColumn(['occurrence_index', 'is_exception']);
        });

        Schema::dropIfExists('appointment_series');
    }
};
