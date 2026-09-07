<?php

namespace App\Http\Requests\PetVetAccess;

use App\Enums\VetAccessLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aceite da solicitação pelo tutor.
 *
 * `access_level` é OBRIGATÓRIO: é aqui que o nível é decidido. Aceitar sem informá-lo era o
 * comportamento antigo, em que o pedido do vet virava concessão sozinho — exatamente a regra
 * que foi invertida. Sem o campo, 422.
 *
 * Autorização fica em `PetVetAccessPolicy::respond` — precisa do registro carregado, o que só
 * acontece no controller.
 */
class AcceptVetAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'access_level' => ['required', Rule::enum(VetAccessLevel::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'access_level.required' => 'Escolha o nível de acesso que deseja conceder ao veterinário.',
            'access_level.enum' => 'Nível de acesso inválido. Use: read, write ou full.',
        ];
    }

    public function grantedLevel(): VetAccessLevel
    {
        return VetAccessLevel::from($this->validated('access_level'));
    }

    public function bodyParameters(): array
    {
        return [
            'access_level' => [
                'description' => 'Nível concedido pelo tutor: read, write ou full. Decisão do tutor — o valor pedido pelo veterinário (requested_access_level) é apenas indicação.',
                'example' => 'read',
            ],
        ];
    }
}
