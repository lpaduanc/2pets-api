<?php

namespace App\Services\Fiscal\Certificate;

use App\Models\FiscalCertificate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Envio e remoção do A1 da organização — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §3. Valida (`FiscalCertificateInspector`), guarda cifrado
 * (`FiscalCertificateVault`) e registra no log de atividade SÓ os metadados públicos (impressão
 * digital, CNPJ do titular, validade) — nunca bytes, senha ou caminho.
 */
final class FiscalCertificateService
{
    private const LOG_NAME = 'fiscal';

    public function __construct(
        private readonly FiscalCertificateInspector $inspector,
        private readonly FiscalCertificateVault $vault,
    ) {}

    public function current(Organization $organization): ?FiscalCertificate
    {
        return $this->vault->metadata($organization->id);
    }

    public function upload(Organization $organization, #[\SensitiveParameter] string $pfx, #[\SensitiveParameter] string $password, User $user): FiscalCertificate
    {
        $inspected = $this->inspector->inspect($pfx, $password, $organization->cnpj);

        return DB::transaction(function () use ($organization, $pfx, $password, $inspected, $user): FiscalCertificate {
            $replaced = $this->vault->metadata($organization->id);
            $certificate = $this->vault->store($organization, $pfx, $password, $inspected, $user->id);
            $this->audit($organization, $user, 'fiscal_certificate.uploaded', 'Certificado A1 enviado', $certificate, $replaced?->fingerprint);

            return $certificate;
        });
    }

    public function remove(Organization $organization, User $user): void
    {
        DB::transaction(function () use ($organization, $user): void {
            $removed = $this->vault->remove($organization);
            abort_if($removed === null, 404, 'A empresa não tem certificado A1 cadastrado.');
            $this->audit($organization, $user, 'fiscal_certificate.removed', 'Certificado A1 removido', $removed);
        });
    }

    private function audit(Organization $organization, User $user, string $event, string $description, FiscalCertificate $certificate, ?string $replacedFingerprint = null): void
    {
        activity(self::LOG_NAME)
            ->performedOn($organization)
            ->causedBy($user)
            ->event($event)
            ->withProperties(array_filter([
                'fingerprint' => $certificate->fingerprint,
                'subject_document' => $certificate->subject_document,
                'cnpj_match' => $certificate->cnpj_match->value,
                'valid_to' => $certificate->valid_to->toIso8601String(),
                'replaced_fingerprint' => $replacedFingerprint,
            ]))
            ->log($description);
    }
}
