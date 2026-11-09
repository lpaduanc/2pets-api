<?php

namespace App\Services\Organization;

use App\DataTransferObjects\Cnpj;
use App\Enums\FiscalDocumentStatus;
use App\Models\FiscalCertificate;
use App\Models\FiscalDocument;
use App\Models\Organization;
use App\Services\Fiscal\Certificate\FiscalCertificateVault;
use App\Services\Fiscal\LogFiscalProviderGateway;
use Illuminate\Validation\ValidationException;

/**
 * Quando o CNPJ da empresa pode mudar — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md (§3.2 e §5.1).
 *
 * 1. Documento fiscal que já VALE (autorizado, em contingência ou cancelado — o cancelamento é um
 *    evento sobre uma nota que existiu) carrega o CNPJ na chave de acesso e no XML: trocar o CNPJ
 *    da empresa depois deixaria o histórico apontando para outro emitente. Daí em diante, CNPJ
 *    novo é empresa nova. Nota da emissão simulada (driver `log`) não conta: não existe perante o
 *    fisco, e travar por ela impediria corrigir um CNPJ digitado errado antes do primeiro envio real.
 * 2. Certificado A1 só assina para a mesma raiz de CNPJ (regra 213 da SEFAZ, a mesma do
 *    `FiscalCertificateInspector`). Trocar para outra raiz com o A1 antigo guardado faria toda
 *    transmissão falhar — o dono remove o certificado primeiro e envia o da empresa nova.
 */
final class OrganizationCnpjChangeGuard
{
    /** Status em que o documento já existe perante o fisco (ou existiu, no caso do cancelado). */
    private const LOCKING_STATUSES = [
        FiscalDocumentStatus::AUTHORIZED,
        FiscalDocumentStatus::CONTINGENCY,
        FiscalDocumentStatus::CANCELLED,
    ];

    public function __construct(private readonly FiscalCertificateVault $vault) {}

    /** @throws ValidationException */
    public function assertCanChange(Organization $organization, string $newCnpj): void
    {
        if ($organization->cnpj === $newCnpj) {
            return;
        }

        if ($this->hasIssuedDocuments($organization)) {
            throw ValidationException::withMessages(['cnpj' => 'O CNPJ não pode mais ser alterado: esta empresa já tem nota fiscal emitida com ele. Para outro CNPJ, cadastre uma nova empresa.']);
        }

        if (! $this->certificateAccepts($organization, $newCnpj)) {
            throw ValidationException::withMessages(['cnpj' => 'O certificado A1 cadastrado é de outro CNPJ. Remova o certificado em Configurações fiscais antes de trocar o CNPJ da empresa.']);
        }
    }

    /** Para a tela: o campo CNPJ já nasce bloqueado quando a trava 1 vale. */
    public function hasIssuedDocuments(Organization $organization): bool
    {
        return FiscalDocument::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', array_map(fn (FiscalDocumentStatus $status): string => $status->value, self::LOCKING_STATUSES))
            ->where('provider', '!=', LogFiscalProviderGateway::NAME)
            ->exists();
    }

    private function certificateAccepts(Organization $organization, string $newCnpj): bool
    {
        $certificate = $this->vault->metadata($organization->id);

        return $certificate === null || self::sameRoot($certificate, $newCnpj);
    }

    private static function sameRoot(FiscalCertificate $certificate, string $newCnpj): bool
    {
        return Cnpj::tryParse($certificate->subject_document)?->root() === Cnpj::tryParse($newCnpj)?->root();
    }
}
