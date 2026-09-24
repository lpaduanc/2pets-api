<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marca "endereço salvo sem coordenada" (`App\Enums\Location\GeocodingStatus`).
 *
 * Antes, uma falha de geocoding no cadastro/edição simplesmente não gravava coordenada — e
 * na edição o ponto ANTIGO continuava valendo, deixando o profissional na busca no endereço
 * errado, sem rastro nenhum. Com o status, a falha é visível e reprocessável
 * (`geocoding:retry-failed`).
 *
 * Backfill: quem já tem coordenada vira `resolved`; o resto fica `null` ("nunca tentou"),
 * não `failed` — marcar legado como falha dispararia geocoding pago para contas sem
 * endereço nenhum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('geocoding_status', 20)->nullable()->after('longitude');
            $table->timestamp('geocoded_at')->nullable()->after('geocoding_status');
        });

        DB::table('users')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->update(['geocoding_status' => 'resolved']);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // O reprocessamento só lê as falhas — índice parcial minúsculo em vez de um índice
        // sobre a coluna inteira, que é quase toda `resolved`.
        DB::statement("CREATE INDEX IF NOT EXISTS idx_users_geocoding_failed ON users (id) WHERE geocoding_status = 'failed'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_users_geocoding_failed');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['geocoding_status', 'geocoded_at']);
        });
    }
};
