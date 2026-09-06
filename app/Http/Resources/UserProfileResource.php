<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape único de `GET` e `PUT /api/profile`, acordado com o frontend: a
 * resposta do PUT precisa ser idêntica à do GET para que o formulário de
 * edição reatribua a resposta direto ao estado, sem remapear campos.
 *
 * O controller garante `professional`/`company` carregados (mesmo que
 * `null`) e `pets_count` via `loadCount('pets')` antes de instanciar este
 * resource — os três campos aqui assumem que isso já aconteceu.
 */
class UserProfileResource extends JsonResource
{
    /**
     * O contrato acordado com o frontend não usa o wrapper `data` padrão do
     * `JsonResource` — o corpo da resposta é o próprio objeto de perfil.
     */
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'user_type' => $this->user_type,
            'phone' => $this->phone,
            'cpf' => $this->cpf,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'gender' => $this->gender,
            'occupation' => $this->occupation,
            'profile_completed' => $this->profile_completed,
            'registration_status' => $this->registration_status,
            'email_verified' => $this->email_verified,
            'pets_count' => $this->pets_count ?? 0,
            'created_at' => $this->created_at?->toIso8601String(),
            'address' => $this->addressPayload(),
            'professional' => $this->professionalPayload(),
            'company' => $this->companyPayload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addressPayload(): array
    {
        return [
            'street' => $this->address,
            'number' => $this->number,
            'complement' => $this->complement,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
        ];
    }

    private function professionalPayload(): ?ProfessionalProfileResource
    {
        return $this->professional === null
            ? null
            : new ProfessionalProfileResource($this->professional);
    }

    private function companyPayload(): ?CompanyProfileResource
    {
        return $this->company === null
            ? null
            : new CompanyProfileResource($this->company);
    }
}
