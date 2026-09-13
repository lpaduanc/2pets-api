<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'user_type' => $this->user_type,

            // Profile status
            'email_verified' => $this->email_verified,
            'profile_completed' => $this->profile_completed,
            'registration_status' => $this->registration_status,
            'is_suspended' => $this->is_suspended,
            'is_deactivated' => $this->resource->isDeactivated(),
            'deactivated_at' => $this->deactivated_at?->toISOString(),
            'deactivation_reason' => $this->when(
                $this->shouldShowSensitiveData($request),
                fn () => $this->deactivation_reason?->value
            ),
            'deactivation_note' => $this->when($this->shouldShowSensitiveData($request), $this->deactivation_note),

            // Personal data
            'cpf' => $this->when($this->shouldShowSensitiveData($request), $this->cpf),
            'cnpj' => $this->cnpj,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'gender' => $this->gender,
            'occupation' => $this->occupation,

            // Address
            'address' => [
                'street' => $this->address,
                'number' => $this->number,
                'complement' => $this->complement,
                'neighborhood' => $this->neighborhood,
                'city' => $this->city,
                'state' => $this->state,
                'zip_code' => $this->zip_code,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
            ],

            // Avatar
            'avatar_url' => $this->when(
                $this->relationLoaded('media'),
                fn () => $this->getFirstMediaUrl('avatar')
            ),

            // Spatie roles/permissions (uses eager-loaded relation when available)
            'roles' => $this->when(
                $this->relationLoaded('roles'),
                fn () => $this->roles->pluck('name'),
                fn () => method_exists($this->resource, 'getRoleNames') ? $this->getRoleNames() : []
            ),

            // Relationships (only when loaded)
            'professional' => new ProfessionalResource($this->whenLoaded('professional')),
            'company' => $this->whenLoaded('company'),

            // Organizações às quais o usuário está ativamente vinculado (split Pessoa/Organização)
            // — sempre array, nunca objeto único: a mesma pessoa pode ter vínculo com N
            // organizações. Vazio de verdade para tutor e vet volante.
            //
            // A versão anterior só preenchia quando a relação já estivesse carregada e devolvia
            // `[]` caso contrário. Isso MENTIA: "não carreguei" saía indistinguível de "não
            // pertence a nenhuma". Só `GET /user` fazia o eager load, então o payload de
            // `POST /login` dizia que o dono de clínica não tinha organização alguma — e o app,
            // que guarda o usuário do login, mandava completar um cadastro já completo.
            //
            // `loadMissing` resolve na origem: carrega uma vez se faltar, não recarrega se já
            // veio. Seguro aqui porque `UserResource` nunca é usado como coleção (todos os 16
            // call sites são de usuário único), então não há N+1 a temer — e nenhum call site
            // futuro precisa lembrar de nada.
            'organizations' => UserOrganizationResource::collection(
                $this->resource
                    ->loadMissing('activeOrganizationMemberships.organization')
                    ->primaryMembershipPerOrganization()
            ),
            'pets_count' => $this->when($this->pets_count !== null, $this->pets_count),

            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Determine if sensitive data should be shown.
     * Only the user themselves or an admin can see CPF.
     */
    private function shouldShowSensitiveData(Request $request): bool
    {
        $authUser = $request->user();
        if (! $authUser) {
            return false;
        }

        return $authUser->id === $this->id
            || $authUser->role === 'admin'
            || (method_exists($authUser, 'hasAnyRole') && $authUser->hasAnyRole(['admin', 'super_admin']));
    }
}
