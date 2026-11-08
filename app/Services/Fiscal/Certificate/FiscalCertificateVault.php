<?php

namespace App\Services\Fiscal\Certificate;

use App\DataTransferObjects\Fiscal\InspectedCertificate;
use App\Exceptions\Fiscal\FiscalCertificateUnavailable;
use App\Exceptions\Fiscal\FiscalSecretUnreadable;
use App\Models\FiscalCertificate;
use App\Models\Organization;
use App\Support\Fiscal\FiscalSecretCipher;
use NFePHP\Common\Certificate;

/**
 * Único lugar que escreve e lê o A1 cifrado — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §3.3. O `.pfx` e a senha ficam no banco, cifrados com
 * contexto da organização (`FiscalSecretCipher`); o certificado aberto só existe em memória,
 * durante a assinatura/transmissão. `organizations.certificate_ref` passa a apontar para a linha
 * de `fiscal_certificates` (nunca para um arquivo) e `certificate_expires_at` espelha a validade.
 */
final class FiscalCertificateVault
{
    private const REFERENCE_PREFIX = 'fiscal_certificates:';

    public function __construct(private readonly FiscalSecretCipher $cipher) {}

    public function store(Organization $organization, #[\SensitiveParameter] string $pfx, #[\SensitiveParameter] string $password, InspectedCertificate $inspected, ?int $userId): FiscalCertificate
    {
        // `organization_id` fora do `$fillable` de propósito: só o cofre amarra o A1 à organização.
        $certificate = $this->metadata($organization->id) ?? new FiscalCertificate;
        $certificate->organization_id = $organization->id;
        $certificate->fill($inspected->attributes() + ['uploaded_by' => $userId]);
        $certificate->pfx_ciphertext = $this->cipher->encrypt($pfx, self::context($organization->id, 'pfx'));
        $certificate->password_ciphertext = $this->cipher->encrypt($password, self::context($organization->id, 'password'));
        $certificate->save();

        $organization->forceFill([
            'certificate_ref' => self::REFERENCE_PREFIX.$certificate->id,
            'certificate_expires_at' => $inspected->validTo,
        ])->save();

        return $certificate;
    }

    public function remove(Organization $organization): ?FiscalCertificate
    {
        $certificate = $this->metadata($organization->id);
        $certificate?->delete();
        $organization->forceFill(['certificate_ref' => null, 'certificate_expires_at' => null])->save();

        return $certificate;
    }

    public function metadata(int $organizationId): ?FiscalCertificate
    {
        return FiscalCertificate::query()->where('organization_id', $organizationId)->first();
    }

    /**
     * Certificado aberto para assinar/transmitir.
     *
     * @throws FiscalCertificateUnavailable
     */
    public function open(int $organizationId): Certificate
    {
        $stored = $this->metadata($organizationId);

        if ($stored === null) {
            throw new FiscalCertificateUnavailable('A empresa não tem certificado A1 cadastrado.');
        }

        if ($stored->isExpired()) {
            throw new FiscalCertificateUnavailable(sprintf('O certificado A1 venceu em %s.', $stored->valid_to->format('d/m/Y')));
        }

        return $this->decrypt($stored);
    }

    private function decrypt(FiscalCertificate $stored): Certificate
    {
        try {
            return Certificate::readPfx(
                $this->cipher->decrypt($stored->pfx_ciphertext, self::context($stored->organization_id, 'pfx')),
                $this->cipher->decrypt($stored->password_ciphertext, self::context($stored->organization_id, 'password')),
            );
        } catch (FiscalSecretUnreadable|\NFePHP\Common\Exception\CertificateException) {
            throw new FiscalCertificateUnavailable('O certificado A1 guardado não pôde ser aberto; envie-o novamente.');
        }
    }

    private static function context(int $organizationId, string $field): string
    {
        return "fiscal_certificates:{$organizationId}:{$field}";
    }
}
