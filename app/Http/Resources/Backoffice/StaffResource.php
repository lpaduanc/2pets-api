<?php

namespace App\Http\Resources\Backoffice;

use App\Enums\BackofficeRole;
use App\Http\Middleware\EnsureBackofficeAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Membro da equipe do 2pets Admin — tanto o "eu" do login quanto as linhas da tela de equipe.
 *
 * @mixin \App\Models\User
 */
class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = collect(BackofficeRole::cases())->first(fn (BackofficeRole $r) => $this->resource->hasRole($r->value));

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $role?->value,
            'role_label' => $role?->label(),
            'permissions' => EnsureBackofficeAccess::effectivePermissions($this->resource),
            'is_active' => ! $this->is_suspended,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
