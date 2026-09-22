<?php

namespace App\Http\Resources\Import;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `DataImport` (item 26 do backlog gap-simplesvet). `file_path` nunca é exposto — o arquivo
 * original não fica acessível por URL, nem indireta (critério de aceite da spec).
 *
 * @mixin \App\Models\DataImport
 */
class DataImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity' => $this->entity->value,
            'entity_label' => $this->entity->name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'total_rows' => $this->total_rows,
            'valid_rows' => $this->valid_rows,
            'error_rows' => $this->error_rows,
            'imported_rows' => $this->imported_rows,
            'column_mapping' => $this->column_mapping,
            'raw_headers' => $this->raw_headers,
            'options' => $this->options,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
