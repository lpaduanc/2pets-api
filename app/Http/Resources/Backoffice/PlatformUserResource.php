<?php

namespace App\Http\Resources\Backoffice;

use App\Enums\ProfessionalType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Linha da lista "Usuários" do 2pets Admin: tutor (app do tutor) ou conta do 2pets Pro.
 *
 * @mixin \App\Models\User
 */
class PlatformUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'app' => $this->role === 'tutor' ? 'tutor' : 'pro',
            'user_type' => $this->user_type,
            'user_type_label' => self::userTypeLabel($this->user_type),
            'account_status' => $this->resource->accountStatus()->value,
            'email_verified' => (bool) $this->email_verified,
            'profile_completed' => (bool) $this->profile_completed,
            'registration_status' => $this->registration_status,
            'city' => $this->city,
            'state' => $this->state,
            'pets_count' => $this->whenCounted('pets'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public static function userTypeLabel(?string $userType): string
    {
        return match ($userType) {
            'tutor', null => 'Tutor',
            'company' => 'Empresa',
            default => ProfessionalType::tryFrom($userType)?->label() ?? $userType,
        };
    }
}
