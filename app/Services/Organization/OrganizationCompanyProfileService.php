<?php

namespace App\Services\Organization;

use App\Enums\FiscalCertificateMatch;
use App\Models\Organization;
use App\Models\User;
use App\Services\Fiscal\Certificate\FiscalCertificateVault;
use App\Services\Location\UserAddressGeocoder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Edição dos dados cadastrais da empresa pelo próprio dono (razão social, CNPJ e endereço) —
 * o que a transmissão própria precisa no emitente (spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §5.1/§6) e até aqui só era gravado no cadastro.
 *
 * Endereço novo re-geocodifica, como o cadastro (que copia a coordenada geocodificada do
 * representante) e a edição de perfil: a coordenada da organização alimenta o leva e traz
 * (`TransportQuoteService`). Mesma regra do produto: não resolveu ⇒ coordenada anulada, nunca o
 * ponto do endereço antigo.
 */
final class OrganizationCompanyProfileService
{
    private const LOG_NAME = 'organization';

    private const AUDIT_EVENT = 'organization.company_profile_updated';

    /** @var list<string> */
    private const AUDITED_FIELDS = ['business_name', 'cnpj', 'address', 'number', 'complement', 'neighborhood', 'city', 'state', 'zip_code'];

    public function __construct(
        private readonly OrganizationCnpjChangeGuard $cnpjGuard,
        private readonly UserAddressGeocoder $addressGeocoder,
        private readonly FiscalCertificateVault $certificates,
    ) {}

    /** @param  array<string, string|null>  $data  já validado (`UpdateOrganizationCompanyProfileRequest`) */
    public function update(Organization $organization, array $data, User $user): Organization
    {
        $this->cnpjGuard->assertCanChange($organization, (string) $data['cnpj']);
        $organization->fill(Arr::only($data, self::AUDITED_FIELDS));
        $changes = $this->auditedChanges($organization);
        $this->refreshCoordinatesIfAddressChanged($organization);

        DB::transaction(function () use ($organization, $changes, $user): void {
            $this->saveWithoutModelLog($organization);
            $this->syncCertificateMatch($organization);
            $this->audit($organization, $user, $changes);
        });

        return $organization->fresh();
    }

    /**
     * Geocodifica FORA da transação (é HTTP para o provedor de mapas). Só quando um campo que
     * entra no texto geocodificado mudou de fato — o PUT manda o formulário inteiro.
     */
    private function refreshCoordinatesIfAddressChanged(Organization $organization): void
    {
        if (! $organization->isDirty(UserAddressGeocoder::ADDRESS_FIELDS)) {
            return;
        }

        $coordinates = $this->addressGeocoder->coordinateAttributes($organization->only(UserAddressGeocoder::ADDRESS_FIELDS));
        $organization->fill(Arr::only($coordinates, ['latitude', 'longitude']));
    }

    /**
     * O `LogsActivity` do model já registraria razão social/CNPJ; aqui sai um registro só, com
     * antes/depois do endereço também — dois registros da mesma edição confundiriam a auditoria.
     */
    private function saveWithoutModelLog(Organization $organization): void
    {
        $organization->disableLogging();

        try {
            $organization->save();
        } finally {
            $organization->enableLogging();
        }
    }

    /** Filial ↔ matriz: o A1 da mesma raiz continua valendo, mas `cnpj_match` precisa acompanhar. */
    private function syncCertificateMatch(Organization $organization): void
    {
        $certificate = $this->certificates->metadata($organization->id);

        if ($certificate === null) {
            return;
        }

        $certificate->update([
            'cnpj_match' => $certificate->subject_document === $organization->cnpj ? FiscalCertificateMatch::EXACT : FiscalCertificateMatch::ROOT,
        ]);
    }

    /** @return array{old: array<string, mixed>, new: array<string, mixed>} */
    private function auditedChanges(Organization $organization): array
    {
        $dirty = array_keys(Arr::only($organization->getDirty(), self::AUDITED_FIELDS));

        return [
            'old' => Arr::only($organization->getOriginal(), $dirty),
            'new' => Arr::only($organization->getAttributes(), $dirty),
        ];
    }

    /** @param  array{old: array<string, mixed>, new: array<string, mixed>}  $changes */
    private function audit(Organization $organization, User $user, array $changes): void
    {
        if ($changes['new'] === []) {
            return;
        }

        activity(self::LOG_NAME)
            ->performedOn($organization)
            ->causedBy($user)
            ->event(self::AUDIT_EVENT)
            ->withProperties($changes)
            ->log('Dados cadastrais da empresa atualizados');
    }
}
