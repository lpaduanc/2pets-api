<?php

namespace App\Services\Medical\Immunization;

use App\Models\PetImmunizationDose;
use App\Models\User;
use App\Models\Vaccination;
use App\Services\Inventory\ClinicalStockDeductionService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * `POST pets/{pet}/immunization-plans/{plan}/doses/{dose}/apply` — contrato spec 13, regra 8:
 * a dose aplicada vira uma linha em `vaccinations` (mesma tabela que já carrega
 * lote/fabricante/`product_id`/`product_batch_id`/`expiry_date`, reaproveitada aqui
 * independente do `group` do produto — vacina, vermífugo ou antiparasitário), e o vínculo de
 * estoque reaproveita `ClinicalStockDeductionService` (mesma trava de saldo/lote vencido do
 * fluxo avulso já existente em `PetHealthRecordsController`), agora sobre
 * `Product`/`ProductBatch` (consolidação de estoque, docs/gap-simplesvet/specs/
 * produtos-estoque-consolidado-spec.md).
 */
final class ImmunizationDoseApplicationService
{
    public function __construct(
        private readonly ImmunizationProtocolSchedulerService $scheduler,
        private readonly ClinicalStockDeductionService $stockDeductionService,
    ) {}

    /**
     * @throws \App\Exceptions\Inventory\InsufficientStockException
     * @throws \App\Exceptions\Inventory\ExpiredBatchException
     * @throws \App\Exceptions\Inventory\InventoryAccessDeniedException
     */
    public function apply(
        PetImmunizationDose $dose,
        User $appliedBy,
        CarbonInterface $appliedAt,
        ?int $productId,
        ?int $productBatchId,
        bool $confirmExpired,
        ?string $notes,
    ): PetImmunizationDose {
        return DB::transaction(function () use ($dose, $appliedBy, $appliedAt, $productId, $productBatchId, $confirmExpired, $notes): PetImmunizationDose {
            $vaccination = $this->recordApplication($dose, $appliedBy, $appliedAt, $productId, $productBatchId, $confirmExpired, $notes);

            return $this->scheduler->applyDose($dose, $appliedBy, $appliedAt, $vaccination->id);
        });
    }

    private function recordApplication(
        PetImmunizationDose $dose,
        User $appliedBy,
        CarbonInterface $appliedAt,
        ?int $productId,
        ?int $productBatchId,
        bool $confirmExpired,
        ?string $notes,
    ): Vaccination {
        $catalogProduct = $dose->plan->protocol->product;
        $pet = $dose->plan->pet;

        $attributes = [
            'pet_id' => $pet->id,
            'professional_id' => $appliedBy->id,
            'vaccine_name' => $catalogProduct->name,
            'manufacturer' => $catalogProduct->manufacturer,
            'application_date' => $appliedAt->toDateString(),
            'dose_number' => $dose->protocolDose->dose_number,
            'notes' => $notes,
        ];

        if ($productId === null) {
            return Vaccination::create($attributes);
        }

        $lock = $this->stockDeductionService->lockAndValidate($productId, $productBatchId, $appliedBy, $appliedAt, $confirmExpired);

        $attributes['product_id'] = $lock->product->id;
        $attributes['product_batch_id'] = $lock->batch?->id;
        $attributes['expiry_date'] ??= $lock->expiryDate();

        $vaccination = Vaccination::create($attributes);

        $this->stockDeductionService->recordDeduction($lock, $vaccination, $appliedBy->id, $confirmExpired);

        return $vaccination;
    }
}
