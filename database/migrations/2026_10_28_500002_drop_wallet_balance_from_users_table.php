<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users.wallet_balance` (migration `2025_11_23_042520_add_wallet_balance_to_users_table.php`)
 * era uma coluna morta — contrato docs/gap-simplesvet/11-conta-corrente-do-cliente-spec.md:
 * nenhum código de `app/` lia ou escrevia nela antes desta entrega (confirmado por busca no
 * repositório inteiro). O saldo do cliente agora vive em `company_client_accounts`, por
 * clínica — nunca um número global por usuário (o mesmo tutor tem saldo independente em cada
 * clínica onde é atendido).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('wallet_balance');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('wallet_balance', 10, 2)->default(0)->after('email');
        });
    }
};
