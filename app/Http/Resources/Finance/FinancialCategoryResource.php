<?php

namespace App\Http\Resources\Finance;

use App\Models\FinancialCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FinancialCategory
 */
class FinancialCategoryResource extends JsonResource
{
    /**
     * Total das filhas somado pelo controller (soma recursiva) — injetado, não calculado
     * aqui, mesmo padrão de `FinancialAccountResource::balance`.
     */
    public function __construct($resource, private readonly ?float $childrenTotal = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'nature' => $this->nature->value,
            'nature_label' => $this->nature->label(),
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'is_group' => $this->isGroup(),
            'is_system' => $this->is_system,
            'sort_order' => $this->sort_order,
            'active' => $this->active,
            'children_total' => $this->childrenTotal,
            'children' => FinancialCategoryResource::collection($this->whenLoaded('children')),
        ];
    }
}
