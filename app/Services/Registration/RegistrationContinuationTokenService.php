<?php

namespace App\Services\Registration;

use App\Models\RegistrationContinuationToken;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Emite e consome o link de continuação de cadastro — contrato
 * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §6. Ver a migration de
 * `registration_continuation_tokens` para o porquê de ser tabela própria, e não signed URL.
 */
final class RegistrationContinuationTokenService
{
    private const TOKEN_LENGTH = 48;

    private const TTL_DAYS = 7;

    /**
     * Reenvio invalida o anterior — nunca dois links vivos para a mesma conta ao mesmo tempo.
     * Devolve o token em TEXTO PURO: é a única vez que ele existe fora do hash; quem chama é
     * responsável por colocá-lo na URL do e-mail e nunca logá-lo.
     */
    public function issue(User $user): string
    {
        $this->invalidateLiveTokensFor($user);

        $plainToken = Str::random(self::TOKEN_LENGTH);

        RegistrationContinuationToken::create([
            'user_id' => $user->id,
            'token_hash' => $this->hash($plainToken),
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        return $plainToken;
    }

    /** Consulta sem consumir — a tela de continuação usa isto para mostrar nome/CPF/pet. */
    public function peek(string $plainToken): ?User
    {
        return $this->findLive($plainToken)?->user;
    }

    /** Uso único: marca consumido e devolve o usuário, ou `null` se o token não é mais válido. */
    public function consume(string $plainToken): ?User
    {
        $token = $this->findLive($plainToken);

        if ($token === null) {
            return null;
        }

        $token->update(['consumed_at' => now()]);

        return $token->user;
    }

    private function findLive(string $plainToken): ?RegistrationContinuationToken
    {
        return RegistrationContinuationToken::live()
            ->where('token_hash', $this->hash($plainToken))
            ->with('user')
            ->first();
    }

    private function invalidateLiveTokensFor(User $user): void
    {
        RegistrationContinuationToken::query()
            ->where('user_id', $user->id)
            ->live()
            ->update(['invalidated_at' => now()]);
    }

    /**
     * SHA-256, não bcrypt: precisa ser determinístico para permitir `WHERE token_hash = ?`
     * sem conhecer o usuário de antemão (ao contrário de `password_reset_tokens`, indexado
     * por e-mail). A entropia de 48 caracteres aleatórios já inviabiliza força bruta;
     * `hash_equals` seria redundante aqui porque a busca já é por igualdade indexada, não por
     * comparação linear.
     */
    private function hash(string $plainToken): string
    {
        return hash_hmac('sha256', $plainToken, (string) config('app.key'));
    }
}
