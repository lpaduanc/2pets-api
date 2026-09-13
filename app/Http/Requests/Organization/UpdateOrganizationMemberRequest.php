<?php

namespace App\Http\Requests\Organization;

use App\Enums\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageMembers', $this->route('organization')) ?? false;
    }

    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::enum(OrganizationRole::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function bodyParameters(): array
    {
        return [
            'role' => ['description' => 'Novo cargo do membro dentro da organização.'],
            'is_active' => ['description' => 'Ativa ou desativa o vínculo, sem removê-lo.'],
        ];
    }
}
