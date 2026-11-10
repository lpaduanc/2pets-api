<?php

namespace App\Services\Fiscal\Sefaz;

use App\Contracts\FiscalProviderGateway;
use App\DataTransferObjects\Fiscal\FiscalInvoicePayload;
use App\DataTransferObjects\Fiscal\FiscalProviderCapabilities;
use App\DataTransferObjects\Fiscal\FiscalProviderResult;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalEmissionMode;
use App\Enums\FiscalEnvironment;
use App\Exceptions\Fiscal\NfeXmlInvalid;
use App\Models\FiscalDocument;
use App\Models\FiscalNumberInvalidation;
use App\Models\Organization;
use App\Services\Fiscal\Transmission\FiscalTransmissionReadiness;
use NFePHP\Common\Keys;
use NFePHP\NFe\Tools;

/**
 * Driver PRÓPRIO: assina com o A1 da organização e transmite NF-e (55) e NFC-e (65) direto à
 * SEFAZ com o NFePHP — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md. Sem
 * provedor terceiro (decisão do produto). Honra o contrato inteiro de `FiscalProviderGateway`:
 *
 * - falha de transporte (timeout, SOAP fault, cStat 108/109, sem certificado/CSC) ⇒
 *   `FiscalProviderUnavailable` — a máquina de retentativa/contingência existente assume;
 * - rejeição (validação local do XML ou cStat da SEFAZ) ⇒ resultado `REJECTED` com o motivo;
 * - NFC-e em contingência offline (tpEmis 9) ⇒ assina localmente, gera QR Code e DANFCE, volta
 *   `CONTINGENCY`; `checkStatus` transmite depois (`fiscal:reconcile`).
 */
final class SefazFiscalGateway implements FiscalProviderGateway
{
    public const NAME = 'sefaz';

    private const RANDOM_CODE_MAX = 99999999;

    public function __construct(
        private readonly SefazToolsFactory $tools,
        private readonly NfeXmlBuilder $builder,
        private readonly SefazAuthorization $authorization,
        private readonly SefazEvents $events,
        private readonly FiscalTransmissionReadiness $readiness,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function capabilities(): FiscalProviderCapabilities
    {
        return new FiscalProviderCapabilities(invalidation: true, nfceOfflineContingency: true, returnInvoices: true);
    }

    public function issue(FiscalDocument $document, FiscalInvoicePayload $payload): FiscalProviderResult
    {
        $offline = $payload->kind === FiscalDocumentKind::NFCE && $payload->emissionMode === FiscalEmissionMode::OFFLINE_CONTINGENCY;
        $tools = $this->toolsFor($document);

        try {
            $signed = $this->authorization->previousAttempt($document, $offline) ?? $this->sign($tools, $document, $payload);

            return $this->authorization->submit($tools, $document, $this->accessKeyOf($signed), $signed, $offline);
        } catch (NfeXmlInvalid $invalid) {
            return new FiscalProviderResult(FiscalDocumentStatus::REJECTED, rejectionReason: $invalid->getMessage());
        }
    }

    public function checkStatus(FiscalDocument $document): FiscalProviderResult
    {
        return match ($document->status) {
            FiscalDocumentStatus::CONTINGENCY => $this->authorization->transmitStored($this->toolsFor($document), $document),
            FiscalDocumentStatus::PROCESSING => $this->authorization->query($this->toolsFor($document), $document),
            default => new FiscalProviderResult($document->status, accessKey: $document->access_key, providerId: $document->provider_id, protocol: $document->protocol),
        };
    }

    public function cancel(FiscalDocument $document, string $reason): FiscalProviderResult
    {
        return $this->events->cancel($this->toolsFor($document), $document, $reason);
    }

    public function invalidate(FiscalNumberInvalidation $invalidation): FiscalProviderResult
    {
        $tools = $this->tools->make((int) $invalidation->organization_id, $invalidation->kind, $invalidation->environment ?? FiscalEnvironment::HOMOLOGATION);

        return $this->events->invalidate($tools, $invalidation);
    }

    private function toolsFor(FiscalDocument $document): Tools
    {
        return $this->tools->make((int) $document->organization_id, $document->kind, $document->environment ?? FiscalEnvironment::HOMOLOGATION);
    }

    /** Monta, assina e valida no XSD oficial (o sped-nfe valida dentro do `signNFe`). */
    private function sign(Tools $tools, FiscalDocument $document, FiscalInvoicePayload $payload): string
    {
        $cityCode = $this->readiness->emitterCityCode(Organization::findOrFail($document->organization_id))
            ?? throw NfeXmlInvalid::because(['Município do emitente sem código IBGE: confira cidade/UF ou informe o código na configuração fiscal.']);
        $built = $this->builder->build($payload, now(), $cityCode, $this->randomCode((int) $payload->number));

        return SefazCalls::guard(fn (): string => $tools->signNFe($built->xml));
    }

    private function accessKeyOf(string $signedXml): string
    {
        // A chave pode ter letra nas posições do CNPJ (CNPJ alfanumérico do emitente).
        preg_match('/Id="NFe([0-9A-Z]{44})"/', $signedXml, $match);

        return $match[1] ?? throw NfeXmlInvalid::because(['XML assinado sem chave de acesso.']);
    }

    /** cNF: 8 dígitos aleatórios, diferente do número e fora dos padrões que a SEFAZ recusa (NT 2019.001). */
    private function randomCode(int $number): int
    {
        do {
            $code = random_int(1, self::RANDOM_CODE_MAX);
        } while ($code === $number || ! Keys::cNFIsValid(str_pad((string) $code, 8, '0', STR_PAD_LEFT)));

        return $code;
    }
}
