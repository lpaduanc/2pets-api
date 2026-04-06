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
        if (!$authUser) {
            return false;
        }

        return $authUser->id === $this->id
            || $authUser->role === 'admin'
            || (method_exists($authUser, 'hasAnyRole') && $authUser->hasAnyRole(['admin', 'super_admin']));
    }
}
