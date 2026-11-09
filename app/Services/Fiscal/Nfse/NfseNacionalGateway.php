<?php

namespace App\Services\Fiscal\Nfse;

use App\Contracts\FiscalProviderGateway;
use App\DataTransferObjects\Fiscal\FiscalInvoicePayload;
use App\DataTransferObjects\Fiscal\FiscalProviderCapabilities;
use App\DataTransferObjects\Fiscal\FiscalProviderResult;
use App\Enums\FiscalDocumentStatus;
use App\Exceptions\Fiscal\FiscalCertificateUnavailable;
use App\Exceptions\Fiscal\FiscalProviderUnavailable;
use App\Exceptions\Fiscal\NfeXmlInvalid;
use App\Models\FiscalDocument;
use App\Models\FiscalNumberInvalidation;
use App\Services\Fiscal\Certificate\FiscalCertificateVault;
use NFePHP\Common\Certificate;

/**
 * Driver PRÓPRIO da NFS-e: DPS assinada com o A1 da organização enviada à Sefin Nacional (API do
 * Emissor Público Nacional) — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfse-nacional.md.
 * Honra o contrato inteiro de `FiscalProviderGateway`:
 *
 * - falha de transporte, Sefin fora, A1 ausente/vencido, CA bundle ausente ⇒ `FiscalProviderUnavailable`
 *   (não há contingência offline de NFS-e: o documento fica pendente e a retentativa assume);
 * - rejeição (validação local ou erro da Sefin) ⇒ `REJECTED` com o motivo;
 * - inutilização não existe para DPS: `capabilities().invalidation = false` e `invalidate()` recusa.
 */
final class NfseNacionalGateway implements FiscalProviderGateway
{
    public const NAME = 'nfse_nacional';

    private const NO_INVALIDATION = 'A NFS-e do Sistema Nacional não tem inutilização de numeração.';

    public function __construct(
        private readonly FiscalCertificateVault $vault,
        private readonly NfseAuthorization $authorization,
        private readonly NfseCancellation $cancellation,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function capabilities(): FiscalProviderCapabilities
    {
        return new FiscalProviderCapabilities(invalidation: false, nfceOfflineContingency: false, returnInvoices: false);
    }

    public function issue(FiscalDocument $document, FiscalInvoicePayload $payload): FiscalProviderResult
    {
        $certificate = $this->certificate($document);

        try {
            return $this->authorization->issue($document, $payload, $certificate);
        } catch (NfeXmlInvalid $invalid) {
            return new FiscalProviderResult(FiscalDocumentStatus::REJECTED, rejectionReason: $invalid->getMessage());
        }
    }

    /** Fluxo síncrono: só `processing` consulta (pela DPS); o resto devolve o estado atual. */
    public function checkStatus(FiscalDocument $document): FiscalProviderResult
    {
        $current = new FiscalProviderResult($document->status, accessKey: $document->access_key, providerId: $document->provider_id, protocol: $document->protocol);

        if ($document->status !== FiscalDocumentStatus::PROCESSING || $document->provider_id === null) {
            return $current;
        }

        return $this->authorization->recover($document, $this->certificate($document), $document->provider_id) ?? $current;
    }

    public function cancel(FiscalDocument $document, string $reason): FiscalProviderResult
    {
        return $this->cancellation->cancel($document, $reason, $this->certificate($document));
    }

    public function invalidate(FiscalNumberInvalidation $invalidation): FiscalProviderResult
    {
        return new FiscalProviderResult(FiscalDocumentStatus::REJECTED, rejectionReason: self::NO_INVALIDATION);
    }

    /** @throws FiscalProviderUnavailable */
    private function certificate(FiscalDocument $document): Certificate
    {
        if (! config('fiscal.nfse.enabled')) {
            throw new FiscalProviderUnavailable('Transmissão da NFS-e ao Sistema Nacional desligada na configuração (fiscal.nfse.enabled).');
        }

        try {
            return $this->vault->open((int) $document->organization_id);
        } catch (FiscalCertificateUnavailable $unavailable) {
            throw new FiscalProviderUnavailable($unavailable->getMessage());
        }
    }
}
