<?php

namespace App\Services\Medical;

use App\Enums\PrescriptionKind;
use App\Exceptions\Medical\PrescriptionLifecycleException;
use App\Models\Prescription;
use Illuminate\Support\Facades\DB;

/**
 * `store()`/`update()` de `PrescriptionController` — orquestra a criação/edição da receita e
 * dos itens dentro da mesma transação. Regra de imutabilidade do contrato §1: uma prescrição
 * já emitida nunca é editada por aqui, só por `PrescriptionLifecycleService::cancel()`.
 */
final class PrescriptionWriteService
{
    public function __construct(private readonly PrescriptionItemsWriter $itemsWriter) {}

    /**
     * @param  array<string, mixed>  $data  validado, com a chave `items` (list de arrays)
     */
    public function create(array $data): Prescription
    {
        $items = $data['items'];
        unset($data['items']);

        // O default `simple` existe na migration (nível de banco), mas o Eloquent não relê a
        // linha depois do INSERT — sem isto, a resposta do próprio `store()` devolvia
        // `kind: null` até a próxima leitura, mesmo com a coluna já gravada como `simple`.
        $data['kind'] ??= PrescriptionKind::SIMPLE->value;

        return DB::transaction(function () use ($data, $items): Prescription {
            $prescription = Prescription::create($data);
            $this->itemsWriter->replace($prescription, $items);

            return $prescription;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validado; `items` é opcional (edição parcial)
     */
    public function update(Prescription $prescription, array $data): Prescription
    {
        if (! $prescription->isEditable()) {
            throw PrescriptionLifecycleException::alreadyIssued();
        }

        $items = $data['items'] ?? null;
        unset($data['items']);

        return DB::transaction(function () use ($prescription, $data, $items): Prescription {
            if ($items !== null) {
                $this->itemsWriter->replace($prescription, $items);
            }

            $prescription->update($data);

            return $prescription;
        });
    }

    public function delete(Prescription $prescription): void
    {
        if (! $prescription->isEditable()) {
            throw PrescriptionLifecycleException::alreadyIssued();
        }

        $prescription->delete();
    }
}
