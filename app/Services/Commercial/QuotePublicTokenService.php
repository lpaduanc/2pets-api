<?php

namespace App\Services\Commercial;

use App\Enums\SaleKind;
use App\Models\Sale;
use Illuminate\Support\Str;

/**
 * Link de aprovação de orçamento sem login — docs/gap-simplesvet/24-orcamentos.md, "Segurança".
 *
 * Mesmo desenho de `RegistrationContinuationTokenService`: o token existe em texto puro uma
 * única vez (na resposta do envio, para a clínica compartilhar) e o banco só guarda SHA-256
 * dele. SHA-256 e não bcrypt porque a busca é por igualdade indexada sem saber de antemão qual
 * orçamento é; 48 caracteres aleatórios já tornam força bruta inviável, e a rota ainda é
 * rate-limited.
 *
 * "Uso limitado": o token decide UMA vez (`public_token_used_at`). "Expiração junto com a
 * validade": não há `expires_at` próprio — vale enquanto `valid_until` valer, e quem checa isso
 * é `QuoteService` ao ler o status efetivo.
 */
final class QuotePublicTokenService
{
    private const TOKEN_LENGTH = 48;

    /**
     * Emite (ou reemite) o link. Reenviar o orçamento troca o hash — o link antigo para de
     * funcionar na hora, nunca dois links vivos para o mesmo documento.
     */
    public function issue(Sale $quote): string
    {
        $plainToken = Str::random(self::TOKEN_LENGTH);

        $quote->forceFill([
            'public_token_hash' => $this->hash($plainToken),
            'public_token_used_at' => null,
        ])->save();

        return $plainToken;
    }

    /**
     * Orçamento dono do token, usado ou não — a distinção "link inválido" (404) × "link já
     * usado" (410) é de quem chama. Token de formato impossível nem vai ao banco.
     */
    public function find(string $plainToken, bool $lockForUpdate = false): ?Sale
    {
        if (strlen($plainToken) !== self::TOKEN_LENGTH) {
            return null;
        }

        $query = Sale::query()
            ->where('kind', SaleKind::QUOTE->value)
            ->where('public_token_hash', $this->hash($plainToken));

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function consume(Sale $quote): void
    {
        $quote->forceFill(['public_token_used_at' => now()])->save();
    }

    private function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
