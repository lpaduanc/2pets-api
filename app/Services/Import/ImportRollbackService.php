<?php

namespace App\Services\Import;

use App\Enums\Import\ImportRowStatus;
use App\Enums\StockMovementType;
use App\Models\Appointment;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetImmunizationDose;
use App\Models\Product;
use App\Models\ProfessionalClient;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vaccination;

/**
 * Desfaz uma importação (item 26 do backlog gap-simplesvet, regra 6: "rollback é completo ou
 * não acontece" — remove exatamente os registros criados por ESTA importação, e nada além;
 * um registro já referenciado por algo posterior (pet/agendamento/fatura cadastrado depois)
 * recusa o rollback daquela linha, com explicação, em vez de cascatear).
 *
 * Correlação por `data_import_rows.created_record_id`, não pelo `batch_uuid` do
 * `activity_log` — mais preciso, sem depender de todo write ter passado por
 * `LogBatch::startBatch()` corretamente (ver nota na migration).
 *
 * `clients`, `pets`, `products` e `vaccinations` têm desfazer implementado — cada um com a
 * própria checagem de "já foi usado depois" (a mesma salvaguarda de `clients`, generalizada).
 */
final class ImportRollbackService
{
    /**
     * @return array{rolled_back: int, refused: list<array{row_id: int, reason: string}>}
     */
    public function rollback(DataImport $import): array
    {
        $refused = [];
        $rolledBack = 0;

        foreach ($import->rows()->imported()->get() as $row) {
            $reason = $this->rollbackRow($row);

            if ($reason === null) {
                $rolledBack++;
            } else {
                $refused[] = ['row_id' => $row->id, 'reason' => $reason];
            }
        }

        return ['rolled_back' => $rolledBack, 'refused' => $refused];
    }

    /**
     * @return string|null motivo da recusa, ou null quando desfeito com sucesso (ou quando o
     *                     registro já não existe mais — nada a recusar).
     */
    private function rollbackRow(DataImportRow $row): ?string
    {
        return match ($row->created_record_type) {
            User::class => $this->rollbackClient($row),
            Pet::class => $this->rollbackPet($row),
            Product::class => $this->rollbackProduct($row),
            Vaccination::class => $this->rollbackVaccination($row),
            default => 'Desfazer esta entidade ainda não está disponível.',
        };
    }

    private function rollbackClient(DataImportRow $row): ?string
    {
        $client = User::find($row->created_record_id);

        if ($client === null) {
            return null;
        }

        if ($this->clientHasDownstreamActivity($client)) {
            return 'Cliente já tem pet, agendamento ou fatura cadastrado após a importação.';
        }

        ProfessionalClient::where('client_id', $client->id)->delete();
        $client->delete();
        $this->markSkipped($row);

        return null;
    }

    private function rollbackPet(DataImportRow $row): ?string
    {
        $pet = Pet::find($row->created_record_id);

        if ($pet === null) {
            return null;
        }

        if ($this->petHasDownstreamActivity($pet)) {
            return 'Pet já tem atendimento, vacina ou outro registro clínico cadastrado após a importação.';
        }

        $pet->delete();
        $this->markSkipped($row);

        return null;
    }

    private function rollbackProduct(DataImportRow $row): ?string
    {
        $product = Product::find($row->created_record_id);

        if ($product === null) {
            return null;
        }

        if ($this->productHasDownstreamActivity($product)) {
            return 'Produto já tem venda ou movimentação de estoque além da carga inicial da importação.';
        }

        // O saldo inicial (`StockMovementType::OPENING_BALANCE`) foi gravado pela PRÓPRIA
        // importação (`ImportedProductProvisioner::recordOpeningBalance()`) — é o que esta
        // importação criou, não "uso posterior"; some junto com o produto, nunca fica órfão.
        StockMovement::where('product_id', $product->id)
            ->where('type', StockMovementType::OPENING_BALANCE->value)
            ->delete();
        $product->delete();
        $this->markSkipped($row);

        return null;
    }

    private function rollbackVaccination(DataImportRow $row): ?string
    {
        $vaccination = Vaccination::find($row->created_record_id);

        if ($vaccination === null) {
            return null;
        }

        if (PetImmunizationDose::where('vaccination_id', $vaccination->id)->exists()) {
            return 'Vacina já está vinculada a uma dose de plano de imunização.';
        }

        $vaccination->delete();
        $this->markSkipped($row);

        return null;
    }

    private function markSkipped(DataImportRow $row): void
    {
        $row->update(['status' => ImportRowStatus::SKIPPED->value]);
    }

    private function clientHasDownstreamActivity(User $client): bool
    {
        return $client->pets()->exists()
            || $client->appointmentsAsClient()->exists()
            || $client->invoicesAsClient()->exists();
    }

    /** Mesmo espírito de `clientHasDownstreamActivity()` — "atendimento" cobre qualquer ato clínico já registrado sobre o pet. */
    private function petHasDownstreamActivity(Pet $pet): bool
    {
        return Appointment::where('pet_id', $pet->id)->exists()
            || MedicalRecord::where('pet_id', $pet->id)->exists()
            || $pet->vaccinations()->exists()
            || $pet->dewormings()->exists()
            || $pet->medications()->exists()
            || $pet->examRecords()->exists()
            || $pet->surgeryRecords()->exists()
            || $pet->hospitalizations()->exists()
            || $pet->immunizationPlans()->exists();
    }

    private function productHasDownstreamActivity(Product $product): bool
    {
        return $product->stockMovements()->where('type', '!=', StockMovementType::OPENING_BALANCE->value)->exists()
            || $product->saleItems()->exists()
            || $product->orderItems()->exists();
    }
}
