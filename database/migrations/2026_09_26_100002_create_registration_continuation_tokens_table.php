<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §6.
 *
 * "Link de continuação de cadastro" — leva o tutor de uma conta NÃO reivindicada (criada pelo
 * fluxo de paciente novo, `password IS NULL`) direto para a tela que já sabe quem ele é.
 *
 * É credencial, não um identificador de conveniência: por isso tabela própria em vez de
 * `URL::signedRoute` do Laravel. Um signed URL do Laravel prova só que o SERVIDOR emitiu a
 * URL — não tem estado próprio de "já usado" nem "substituído por um reenvio", então revogar
 * um link antigo exigiria trocar a assinatura (mudar `APP_KEY` — inviável, afetaria toda a
 * aplicação) ou manter uma lista de revogação em outra tabela de qualquer forma. Uma tabela
 * dá as três garantias do contrato de graça: uso único (`consumed_at`), validade
 * (`expires_at`) e invalidação por reenvio (`invalidated_at`) — mesmo desenho que
 * `organization_invitations` já usa para convite de organização.
 *
 * O token em si NUNCA é gravado em texto puro — só o hash SHA-256 (`token_hash`), determinístico
 * o bastante para permitir `WHERE token_hash = ?` sem precisar varrer a tabela comparando
 * hash a hash (ao contrário do bcrypt usado em `password_reset_tokens`, que exige already
 * conhecer o e-mail para localizar a linha antes de comparar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_continuation_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_continuation_tokens');
    }
};
