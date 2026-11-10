<?php

namespace App\Services\Fiscal\Certificate;

use App\DataTransferObjects\Cnpj;
use App\DataTransferObjects\Fiscal\InspectedCertificate;
use App\Enums\FiscalCertificateMatch;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use NFePHP\Common\Certificate;

/**
 * Abre e valida o A1 enviado pela organização — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §3.2: abre com a senha, está dentro da validade e o CNPJ
 * do titular é o da organização ou o da matriz (mesma raiz de 8 caracteres — regra 213 da SEFAZ).
 * O CNPJ do titular (OID 2.16.76.1.3.3) pode ser alfanumérico: normalizado por `Cnpj`, nunca por
 * "só dígitos" (que apagaria as letras e quebraria a comparação).
 *
 * Nenhuma mensagem de erro carrega a senha nem o conteúdo do arquivo; o erro do OpenSSL também é
 * descartado (não ajuda o usuário e poderia expor detalhes do arquivo).
 */
final class FiscalCertificateInspector
{
    /** @throws ValidationException */
    public function inspect(#[\SensitiveParameter] string $pfx, #[\SensitiveParameter] string $password, ?string $organizationCnpj): InspectedCertificate
    {
        $certificate = $this->open($pfx, $password);
        $subject = $this->subjectCnpj($certificate);
        $validFrom = CarbonImmutable::instance($certificate->getValidFrom());
        $validTo = CarbonImmutable::instance($certificate->getValidTo());
        $this->assertWithinValidity($validFrom, $validTo);
        $details = (array) openssl_x509_parse((string) $certificate->publicKey);

        return new InspectedCertificate(
            subjectDocument: $subject->digits,
            subjectName: (string) $certificate->getCompanyName(),
            issuerName: $details['issuer']['CN'] ?? null,
            serialNumber: $details['serialNumberHex'] ?? null,
            fingerprint: (string) openssl_x509_fingerprint((string) $certificate->publicKey, 'sha256'),
            match: $this->match($subject, Cnpj::tryParse($organizationCnpj)),
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

    private function subjectCnpj(Certificate $certificate): Cnpj
    {
        return Cnpj::tryParse((string) $certificate->getCnpj())
            ?? throw ValidationException::withMessages(['certificate' => 'O certificado não traz CNPJ (é um e-CPF?). NF-e e NFC-e exigem um e-CNPJ A1.']);
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

    private function match(Cnpj $subject, ?Cnpj $organizationCnpj): FiscalCertificateMatch
    {
        if ($organizationCnpj === null) {
            throw ValidationException::withMessages(['certificate' => 'Cadastre o CNPJ da empresa antes de enviar o certificado.']);
        }

        if ($subject->digits === $organizationCnpj->digits) {
            return FiscalCertificateMatch::EXACT;
        }

        if ($subject->root() === $organizationCnpj->root()) {
            return FiscalCertificateMatch::ROOT;
        }

        throw ValidationException::withMessages(['certificate' => 'O CNPJ do certificado não é o desta empresa nem o da matriz dela (mesma raiz de CNPJ).']);
    }
}
