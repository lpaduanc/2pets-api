<?php

namespace App\Http\Requests\Commercial;

use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Prévia do custo, margem e markup do serviço (`POST services/cost-preview`) — PLANO 1.3. Corpo
 * com a ficha ainda não salva (serviço novo ou em edição), igual a `purchases/cost-preview`; mesma
 * validação do `PUT services/{id}/supplies`.
 */
class PreviewServiceCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(CommercialScopeResolver $scope): array
    {
        // Serviço novo pode ainda não ter ficha: `items` ausente vale como lista vazia.
        return [
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'items' => ['nullable', 'array', 'max:100'],
        ] + ServiceSupplyRules::for($this->user(), $scope);
    }
}
