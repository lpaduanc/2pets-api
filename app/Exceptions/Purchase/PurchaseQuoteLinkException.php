<?php

namespace App\Exceptions\Purchase;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Link público da cotação que não aceita mais ação (PLANO 3.5) — 410 com `code` estável, no
 * padrão de `QuoteTransitionException` (orçamento ao tutor):
 *
 *  - `purchase_quote_expired`: prazo vencido sem resposta OU cotação encerrada pela clínica;
 *  - `purchase_quote_answered`: o fornecedor já respondeu ou recusou. Leva `data` (a proposta,
 *    só leitura) para a página mostrar o que foi enviado.
 */
final class PurchaseQuoteLinkException extends RuntimeException
{
    public const EXPIRED = 'purchase_quote_expired';

    public const ANSWERED = 'purchase_quote_answered';

    /** @param  array<string, mixed>|null  $data */
    public function __construct(string $message, private readonly string $errorCode, private ?array $data = null)
    {
        parent::__construct($message);
    }

    /** @param  array<string, mixed>  $data */
    public function withData(array $data): self
    {
        $this->data = $data;

        return $this;
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
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'data' => $this->data,
        ], fn ($value): bool => $value !== null), 410);
    }
}
