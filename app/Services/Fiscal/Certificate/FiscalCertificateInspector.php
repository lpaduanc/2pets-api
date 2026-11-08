<?php

namespace App\Services\Fiscal\Certificate;

use App\DataTransferObjects\Fiscal\InspectedCertificate;
use App\Enums\FiscalCertificateMatch;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use NFePHP\Common\Certificate;

/**
 * Abre e valida o A1 enviado pela organização — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §3.2: abre com a senha, está dentro da validade e o CNPJ
 * do titular é o da organização ou o da matriz (mesma raiz de 8 dígitos — regra 213 da SEFAZ).
 *
 * Nenhuma mensagem de erro carrega a senha nem o conteúdo do arquivo; o erro do OpenSSL também é
 * descartado (não ajuda o usuário e poderia expor detalhes do arquivo).
 */
final class FiscalCertificateInspector
{
    private const CNPJ_LENGTH = 14;

    private const CNPJ_ROOT_LENGTH = 8;

    /** @throws ValidationException */
    public function inspect(#[\SensitiveParameter] string $pfx, #[\SensitiveParameter] string $password, ?string $organizationCnpj): InspectedCertificate
    {
        $certificate = $this->open($pfx, $password);
        $subject = (string) preg_replace('/\D/', '', (string) $certificate->getCnpj());
        $this->assertHasCnpj($subject);
        $validFrom = CarbonImmutable::instance($certificate->getValidFrom());
        $validTo = CarbonImmutable::instance($certificate->getValidTo());
        $this->assertWithinValidity($validFrom, $validTo);
        $details = (array) openssl_x509_parse((string) $certificate->publicKey);

        return new InspectedCertificate(
            subjectDocument: $subject,
            subjectName: (string) $certificate->getCompanyName(),
            issuerName: $details['issuer']['CN'] ?? null,
            serialNumber: $details['serialNumberHex'] ?? null,
            fingerprint: (string) openssl_x509_fingerprint((string) $certificate->publicKey, 'sha256'),
            match: $this->match($subject, (string) preg_replace('/\D/', '', (string) $organizationCnpj)),
            validFrom: $validFrom,
            validTo: $validTo,
        );
    }

    private function open(#[\SensitiveParameter] string $pfx, #[\SensitiveParameter] string $password): Certificate
    {
        try {
            return Certificate::readPfx($pfx, $password);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['password' => 'Não foi possível abrir o certificado: senha incorreta ou o arquivo não é um A1 (.pfx/.p12) válido.']);
        }
    }

    private function assertHasCnpj(string $subject): void
    {
        if (strlen($subject) !== self::CNPJ_LENGTH) {
            throw ValidationException::withMessages(['certificate' => 'O certificado não traz CNPJ (é um e-CPF?). NF-e e NFC-e exigem um e-CNPJ A1.']);
        }
    }

    private function assertWithinValidity(CarbonImmutable $validFrom, CarbonImmutable $validTo): void
    {
        if ($validTo->isPast()) {
            throw ValidationException::withMessages(['certificate' => sprintf('O certificado venceu em %s. Envie o certificado renovado.', $validTo->format('d/m/Y'))]);
        }

        if ($validFrom->isFuture()) {
            throw ValidationException::withMessages(['certificate' => sprintf('O certificado só vale a partir de %s.', $validFrom->format('d/m/Y'))]);
        }
    }

    private function match(string $subject, string $organizationCnpj): FiscalCertificateMatch
    {
        if (strlen($organizationCnpj) !== self::CNPJ_LENGTH) {
            throw ValidationException::withMessages(['certificate' => 'Cadastre o CNPJ da empresa antes de enviar o certificado.']);
        }

        if ($subject === $organizationCnpj) {
            return FiscalCertificateMatch::EXACT;
        }

        if (substr($subject, 0, self::CNPJ_ROOT_LENGTH) === substr($organizationCnpj, 0, self::CNPJ_ROOT_LENGTH)) {
            return FiscalCertificateMatch::ROOT;
        }

        throw ValidationException::withMessages(['certificate' => 'O CNPJ do certificado não é o desta empresa nem o da matriz dela (mesma raiz de CNPJ).']);
    }
}
