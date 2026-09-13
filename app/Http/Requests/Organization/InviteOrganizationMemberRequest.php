<?php

namespace App\Http\Requests\Organization;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InviteOrganizationMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageMembers', $this->organization()) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->emailBelongsToActiveMember()) {
                $validator->errors()->add('email', 'Este e-mail já pertence a um membro ativo da organização.');
            } elseif ($this->hasPendingInvitation()) {
                $validator->errors()->add('email', 'Já existe um convite pendente para este e-mail.');
            }
        });
    }

    private function emailBelongsToActiveMember(): bool
    {
        return OrganizationMember::query()
            ->where('organization_id', $this->organization()->id)
            ->where('is_active', true)
            ->whereHas('user', fn ($query) => $query->where('email', $this->input('email')))
            ->exists();
    }

    private function hasPendingInvitation(): bool
    {
        return OrganizationInvitation::query()
            ->where('organization_id', $this->organization()->id)
            ->where('email', $this->input('email'))
            ->pending()
            ->exists();
    }

    private function organization(): Organization
    {
        return $this->route('organization');
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function bodyParameters(): array
    {
        return [
            'email' => ['description' => 'E-mail da pessoa convidada.'],
            'role' => ['description' => 'Cargo dentro da organização: owner, veterinarian, assistant, receptionist, groomer ou technician.'],
        ];
    }
}
