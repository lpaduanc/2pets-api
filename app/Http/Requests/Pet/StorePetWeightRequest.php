<?php

namespace App\Http\Requests\Pet;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `weight`/`notes` são os nomes canônicos: batem com as colunas de `pet_weight_history`
 * e com o que `PetWeightController::store` devolve na resposta (o model é serializado
 * direto). O contrato antigo (`weight_kg`, singular `note`) exigia um par diferente na
 * entrada e na saída — quem integrasse via `weight_kg` recebia 422 ("weight_kg
 * obrigatório") se mandasse `weight`, e `note` era descartado em silêncio porque a
 * validação só conhecia `note`. Os dois nomes antigos continuam aceitos como alias de
 * compatibilidade, sem preferência sobre o nome novo quando os dois vierem juntos.
 *
 * Autorização NÃO mora aqui: quem decide se o requisitante pode escrever neste pet é
 * `AuthorizesPetAccess::resolvePetForWrite()` (tutor-dono OU vet com grant WRITE/FULL),
 * chamado pelo controller. `authorize()` só barra requisição sem usuário autenticado.
 */
class StorePetWeightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'weight' => $this->input('weight_kg'),
            'notes' => $this->input('note'),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'weight' => ['required', 'numeric', 'min:0.01', 'max:200'],
            'measured_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'weight.required' => 'O peso é obrigatório.',
            'weight.numeric' => 'O peso deve ser um número.',
            'weight.min' => 'O peso deve ser maior que zero.',
            'weight.max' => 'O peso informado é maior que o suportado (200kg).',
            'measured_at.required' => 'A data da medição é obrigatória.',
            'measured_at.date' => 'A data da medição é inválida.',
        ];
    }
}
