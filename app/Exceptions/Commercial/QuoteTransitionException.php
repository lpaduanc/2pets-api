<?php

namespace App\Exceptions\Commercial;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Transição de orçamento que o estado atual não permite (doc 24): aprovar orçamento vencido,
 * converter orçamento recusado, enviar sem tutor... Sempre 422 com um `code` estável, porque a
 * tela do tutor e a página pública mostram mensagens diferentes para cada caso.
 *
 * O link público usa 410 (`gone`) em vez de 422: lá o problema não é o pedido, é o link que
 * deixou de existir — ver `$status`.
 */
final class QuoteTransitionException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function expired(int $status = 422): self
    {
        return new self('Este orçamento está vencido. Peça à clínica uma nova versão.', 'quote_expired', $status);
    }

    public static function linkUsed(): self
    {
        return new self('Este link de orçamento já foi utilizado ou não é mais válido.', 'quote_link_used', 410);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], $this->status);
    }
}
