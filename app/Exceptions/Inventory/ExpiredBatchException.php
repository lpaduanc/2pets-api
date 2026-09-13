<?php

namespace App\Exceptions\Inventory;

use App\Models\Inventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * O item de estoque vinculado está vencido na data do ato. Nem bloqueio duro nem passagem
 * silenciosa: pede confirmação explícita (`confirm_expired: true`) — decisão clínica do
 * veterinário, não do sistema (docs/vinculo-estoque-aplicacao-clinica.md item 4).
 */
final class ExpiredBatchException extends RuntimeException
{
    public function __construct(Inventory $inventory)
    {
        $expiry = $inventory->expiry_date?->format('d/m/Y') ?? 'data desconhecida';

        parent::__construct(
            "O lote de \"{$inventory->item_name}\" está vencido desde {$expiry}. Confirme para aplicar mesmo assim."
        );
    }

    /** Resultado de negócio esperado, não incidente: não polui o log com stack trace. */
    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'expired_batch',
        ], 422);
    }
}
