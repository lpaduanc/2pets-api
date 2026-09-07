<?php

namespace App\Http\Requests\PetVetAccess;

use App\Enums\VetAccessLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alteração do nível de um acesso já concedido, por iniciativa do tutor.
 *
 * Consequência direta de "quem decide é o tutor": ele não fica preso à escolha feita no
 * aceite. Sobe e desce livremente, sem nova solicitação do veterinário.
 *
 * Autorização em `PetVetAccessPolicy::changeLevel` (só o tutor dono do pet).
 */
class ChangeVetAccessLevelRequest extends FormRequest
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
            'access_level.required' => 'Informe o novo nível de acesso.',
            'access_level.enum' => 'Nível de acesso inválido. Use: read, write ou full.',
        ];
    }

    public function newLevel(): VetAccessLevel
    {
        return VetAccessLevel::from($this->validated('access_level'));
    }

    public function bodyParameters(): array
    {
        return [
            'access_level' => [
                'description' => 'Novo nível concedido: read, write ou full.',
                'example' => 'full',
            ],
        ];
    }
}
