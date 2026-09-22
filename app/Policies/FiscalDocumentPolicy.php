<?php

namespace App\Policies;

use App\Models\FiscalDocument;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;

/**
 * Contrato docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md — emitir, cancelar,
 * reenviar e ver `fiscal-pending`: só `OWNER` (ou o próprio profissional sem organização).
 * Gera obrigação fiscal e custo por documento no provedor — não fica aberto a
 * `RECEPTIONIST`/`ASSISTANT`, mesmo sendo quem fecha a venda no balcão.
 */
class FiscalDocumentPolicy
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function viewAny(User $user): bool
    {
        return $this->ownsScope($user);
    }

    public function view(User $user, FiscalDocument $document): bool
    {
        return $this->ownsScopeOf($user, $document->organization_id, $document->professional_id);
    }

    public function create(User $user): bool
    {
        return $this->ownsScope($user);
    }

    public function cancel(User $user, FiscalDocument $document): bool
    {
        return $this->ownsScopeOf($user, $document->organization_id, $document->professional_id);
    }

    private function ownsScope(User $user): bool
    {
        $organizationId = $this->scope->primaryOrganizationId($user);

        return $organizationId === null || $user->ownsOrganization($organizationId);
    }

    private function ownsScopeOf(User $user, ?int $organizationId, ?int $professionalId): bool
    {
        if ($organizationId !== null) {
            return $user->ownsOrganization($organizationId);
        }

        return $professionalId === $user->id;
    }
}
