<?php

namespace App\Services\Purchase\Quotes;

use App\Models\PurchaseQuoteSupplier;
use App\Notifications\Support\FrontendRoute;
use Illuminate\Support\Str;

/**
 * Link público do fornecedor na cotação (PLANO 3.5) — mesmo desenho do link do orçamento ao
 * cliente (`QuotePublicTokenService`): 48 caracteres aleatórios, busca pelo SHA-256 indexado,
 * token de formato impossível nem vai ao banco, e a rota é rate-limited.
 *
 * Diferença deliberada: aqui o token também fica CIFRADO (`APP_KEY`, cast `encrypted`) para a
 * clínica copiar o link de novo quando quiser (fornecedor sem e-mail recebe por WhatsApp/telefone,
 * contrato do front: `public_url` no recurso). Só quem enxerga a cotação no escopo vê o link.
 */
final class PurchaseQuoteTokenService
{
    public const TOKEN_LENGTH = 48;

    /** Emite o link uma única vez por fornecedor: reenviar a cotação mantém o mesmo link. */
    public function ensureIssued(PurchaseQuoteSupplier $quoteSupplier): void
    {
        if ($quoteSupplier->token_hash !== null) {
            return;
        }

        $plain = Str::random(self::TOKEN_LENGTH);
        $quoteSupplier->forceFill(['token_hash' => $this->hash($plain), 'token_encrypted' => $plain])->save();
    }

    public function find(string $plainToken, bool $lockForUpdate = false): ?PurchaseQuoteSupplier
    {
        if (strlen($plainToken) !== self::TOKEN_LENGTH) {
            return null;
        }

        return PurchaseQuoteSupplier::query()
            ->where('token_hash', $this->hash($plainToken))
            ->whereHas('quote')
            ->when($lockForUpdate, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    public function publicUrl(PurchaseQuoteSupplier $quoteSupplier): ?string
    {
        $plain = $quoteSupplier->token_encrypted;

        return $plain === null ? null : FrontendRoute::absolute(FrontendRoute::publicPurchaseQuote($plain));
    }

    private function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
