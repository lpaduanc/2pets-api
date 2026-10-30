<?php

namespace App\Http\Requests\Commercial;

use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Substitui a ficha técnica inteira do serviço (`PUT services/{id}/supplies`) — PLANO 1.3. Mesma
 * autorização de editar o serviço: o controller só acha o serviço do próprio profissional.
 */
class UpdateServiceSuppliesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(CommercialScopeResolver $scope): array
    {
        return ServiceSupplyRules::for($this->user(), $scope);
    }
}
