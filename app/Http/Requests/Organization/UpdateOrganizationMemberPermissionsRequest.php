<?php

namespace App\Http\Requests\Organization;

use App\Models\OrganizationMember;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT organizations/{organization}/members/{member}/permissions` — item 22 do backlog
 * gap-simplesvet. Só o dono ativo da organização grava (`OrganizationPolicy::manageMembers`,
 * mesma régua de `UpdateOrganizationMemberRequest`). Substitui a lista inteira de exceções
 * pontuais do membro — mesmo contrato de `PUT .../service-areas` (sync, não merge).
 */
class UpdateOrganizationMemberPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageMembers', $this->route('organization')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }

    /**
     * Ato clínico nunca é concedido por exceção pontual (regra regulatória não-negociável,
     * `OrganizationMember::isClinicalPermission()`) — checado aqui para devolver 422 com o
     * nome exato da permissão rejeitada, em vez de deixar a blindagem em runtime de
     * `OrganizationMember::hasPermission()` ser o único aviso.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('permissions', []) as $permission) {
                if (is_string($permission) && OrganizationMember::isClinicalPermission($permission)) {
                    $validator->errors()->add(
                        'permissions',
                        "\"{$permission}\" é ato clínico e não pode ser concedido por exceção pontual — só quem é veterinário na organização o exerce."
                    );
                }
            }
        });
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function bodyParameters(): array
    {
        return [
            'permissions' => ['description' => 'Lista de permissões nomeadas do catálogo Spatie. Substitui a lista inteira do membro.'],
        ];
    }
}
