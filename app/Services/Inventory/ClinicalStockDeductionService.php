<?php

namespace App\Services\Inventory;

use App\DataTransferObjects\LockedClinicalStock;
use App\Enums\StockMovementType;
use App\Exceptions\Inventory\ExpiredBatchException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InventoryAccessDeniedException;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Stock\StockService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Baixa de estoque por ato clínico atômico (vacina/vermífugo) — docs/vinculo-estoque-aplicacao-
 * clinica.md itens 1, 4, 5 e 6, agora sobre `Product`/`ProductBatch`/`StockService` (consolidação
 * de estoque, docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md).
 *
 * `lockAndValidate()` deve rodar DENTRO de uma `DB::transaction()` já aberta pelo chamador, e
 * ANTES de criar o registro clínico: assim uma falha de estoque nunca deixa uma vacinação ou
 * vermifugação órfã para o rollback desfazer — ela nem chega a ser criada.
 */
final class ClinicalStockDeductionService
{
    /** Uma dose aplicada = uma unidade decrementada — regra 2 da spec de consolidação (sem fracionamento). */
    private const DEDUCTION_QUANTITY = 1;

    public function __construct(
        private readonly CommercialScopeResolver $scopeResolver,
        private readonly StockService $stock,
    ) {}

    /**
     * @throws InventoryAccessDeniedException Produto de outra organização/profissional.
     * @throws ExpiredBatchException Lote vencido na data do ato, sem `confirm_expired`.
     * @throws InsufficientStockException Saldo menor que 1 unidade.
     */
    public function lockAndValidate(
        int $productId,
        ?int $productBatchId,
        User $requester,
        CarbonInterface $applicationDate,
        bool $confirmExpired,
    ): LockedClinicalStock {
        $product = Product::query()->lockForUpdate()->findOrFail($productId);
        $this->guardAccess($product, $requester);

        $lock = new LockedClinicalStock($product, $this->lockBatch($product, $productBatchId));

        $this->guardExpiry($lock, $applicationDate, $confirmExpired);
        $this->guardStock($lock);

        return $lock;
    }

    /**
     * Decrementa 1 unidade via `StockService` (livro `stock_movements`, `balance_after`
     * recalculado sob lock) e amarra o movimento ao registro clínico que o originou.
     */
    public function recordDeduction(
        LockedClinicalStock $lock,
        Model $reference,
        int $professionalId,
        bool $confirmedExpired,
    ): void {
        $this->stock->out($lock->product, StockMovementType::INTERNAL_USE, self::DEDUCTION_QUANTITY, [
            'batch_id' => $lock->batch?->id,
            'reference' => $reference,
            'user' => $professionalId,
            'notes' => $confirmedExpired
                ? 'Lote vencido aplicado mediante confirmação explícita do profissional.'
                : null,
        ]);
    }

    private function lockBatch(Product $product, ?int $productBatchId): ?ProductBatch
    {
        if ($productBatchId === null) {
            return null;
        }

        return $product->batches()->lockForUpdate()->findOrFail($productBatchId);
    }

    private function guardAccess(Product $product, User $requester): void
    {
        if (! $this->scopeResolver->userCanAccess($product, $requester)) {
            throw new InventoryAccessDeniedException;
        }
    }

    private function guardExpiry(LockedClinicalStock $lock, CarbonInterface $applicationDate, bool $confirmExpired): void
    {
        $expiry = $lock->expiryDate();
        $isExpired = $expiry !== null && $expiry->lt($applicationDate);

        if ($isExpired && ! $confirmExpired) {
            throw new ExpiredBatchException($lock);
        }
    }

    private function guardStock(LockedClinicalStock $lock): void
    {
        $balance = $lock->batch?->quantity ?? (int) $lock->product->stock_quantity;

        if ($balance < self::DEDUCTION_QUANTITY) {
            throw new InsufficientStockException($lock);
        }
    }
}
