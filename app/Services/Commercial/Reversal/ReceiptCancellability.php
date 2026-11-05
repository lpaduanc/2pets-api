<?php

namespace App\Services\Commercial\Reversal;

use App\Enums\ReceivableStatus;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SaleReceipt;
use App\Models\User;
use App\Services\Commercial\Authorization\SalePermissionGate;

/**
 * Pode estornar este recebimento agora? (PLANO 2.3) — para o front mostrar a ação "Estornar" só
 * quando faz sentido e dizer por que não. Mesmas regras do `ReceiptCancellationService`; a
 * permissão é lida uma vez por venda no request.
 */
final class ReceiptCancellability
{
    public const ALREADY_REVERSED = 'already_reversed';

    public const SALE_CANCELLED = 'sale_cancelled';

    public const RECEIVABLE_SETTLED = 'receivable_settled';

    public const FORBIDDEN = 'forbidden';

    /** @var array<string, bool> por "usuário:venda" */
    private array $allowedBySale = [];

    public function __construct(private readonly SalePermissionGate $permissions) {}

    /** Motivo de não poder estornar, ou `null` quando pode. */
    public function blockedReason(SaleReceipt $receipt, ?User $user): ?string
    {
        return match (true) {
            $receipt->isReversed() => self::ALREADY_REVERSED,
            $this->sale($receipt)->status === SaleStatus::CANCELLED => self::SALE_CANCELLED,
            $this->hasSettledReceivable($receipt) => self::RECEIVABLE_SETTLED,
            $user === null || ! $this->allowed($receipt, $user) => self::FORBIDDEN,
            default => null,
        };
    }

    private function hasSettledReceivable(SaleReceipt $receipt): bool
    {
        $blocking = [ReceivableStatus::ANTICIPATED, ReceivableStatus::SETTLED];

        return $receipt->relationLoaded('receivables')
            ? $receipt->receivables->contains(fn ($receivable): bool => in_array($receivable->status, $blocking, true))
            : $receipt->receivables()->whereIn('status', array_map(fn (ReceivableStatus $status): string => $status->value, $blocking))->exists();
    }

    private function allowed(SaleReceipt $receipt, User $user): bool
    {
        return $this->allowedBySale[$user->id.':'.$receipt->sale_id] ??= $this->permissions->allows($user, $this->sale($receipt), SalePermissionGate::CANCEL_RECEIPT);
    }

    private function sale(SaleReceipt $receipt): Sale
    {
        return $receipt->relationLoaded('sale') ? $receipt->sale : $receipt->setRelation('sale', Sale::find($receipt->sale_id))->sale;
    }
}
