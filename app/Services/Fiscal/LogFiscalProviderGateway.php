<?php

namespace App\Services\Fiscal;

use App\Contracts\FiscalProviderGateway;
use App\DataTransferObjects\Fiscal\FiscalInvoicePayload;
use App\DataTransferObjects\Fiscal\FiscalProviderCapabilities;
use App\DataTransferObjects\Fiscal\FiscalProviderResult;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalEmissionMode;
use App\Models\FiscalDocument;
use App\Models\FiscalNumberInvalidation;
use App\Models\Organization;
use App\Support\Fiscal\FiscalAccessKey;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Driver PADRÃO do ambiente — contrato docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md
 * e docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §5: "a emissão 'funciona' (grava documento
 * com status simulado e loga o payload) sem nenhuma credencial". Nunca fala com SEFAZ/prefeitura.
 *
 * Autoriza IMEDIATAMENTE com o número que nós reservamos e uma chave de 44 dígitos com DV válido
 * (`FiscalAccessKey`). NFC-e em contingência offline volta `CONTINGENCY` e só vira autorizada na
 * consulta depois que a organização sai da contingência — simula a transmissão posterior.
 */
final class LogFiscalProviderGateway implements FiscalProviderGateway
{
    public const NAME = 'log';

    /** Máximo do `cNF` (código numérico aleatório de 8 dígitos da chave). */
    private const RANDOM_CODE_MAX = 99999999;

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
        $accessKey = $this->accessKey($document, $payload);

        Log::info('fiscal.issue (driver=log, simulado)', [
            'fiscal_document_id' => $document->id,
            'sale_id' => $document->sale_id,
            'kind' => $document->kind->value,
            'total' => (string) $document->total,
            'access_key' => $accessKey,
            'payload' => $payload->toArray(),
        ]);

        $status = $this->isOfflineContingency($payload) ? FiscalDocumentStatus::CONTINGENCY : FiscalDocumentStatus::AUTHORIZED;

        return new FiscalProviderResult($status, accessKey: $accessKey, providerId: $accessKey, protocol: $this->protocolFor($status));
    }

    /**
     * Contingência: autoriza quando a organização já saiu dela (a "transmissão" do driver fake);
     * processando: autoriza. O resto devolve o estado atual — a reconciliação funciona igual com
     * qualquer driver.
     */
    public function checkStatus(FiscalDocument $document): FiscalProviderResult
    {
        $status = match (true) {
            $document->status === FiscalDocumentStatus::CONTINGENCY && $this->organizationStillInContingency($document) => FiscalDocumentStatus::CONTINGENCY,
            $document->status->awaitsProvider() => FiscalDocumentStatus::AUTHORIZED,
            default => $document->status,
        };

        return new FiscalProviderResult(
            $status,
            accessKey: $document->access_key,
            providerId: $document->provider_id,
            protocol: $status === FiscalDocumentStatus::AUTHORIZED ? ($document->protocol ?? $this->protocolFor($status)) : $document->protocol,
        );
    }

    public function cancel(FiscalDocument $document, string $reason): FiscalProviderResult
    {
        Log::info('fiscal.cancel (driver=log, simulado — janela legal real depende da UF)', [
            'fiscal_document_id' => $document->id,
            'reason' => $reason,
        ]);

        return new FiscalProviderResult(FiscalDocumentStatus::CANCELLED, protocol: (string) Str::uuid());
    }

    public function invalidate(FiscalNumberInvalidation $invalidation): FiscalProviderResult
    {
        Log::info('fiscal.invalidate (driver=log, simulado)', [
            'fiscal_number_invalidation_id' => $invalidation->id,
            'kind' => $invalidation->kind->value,
            'series' => $invalidation->series,
            'from' => $invalidation->number_from,
            'to' => $invalidation->number_to,
        ]);

        return new FiscalProviderResult(FiscalDocumentStatus::AUTHORIZED, providerId: (string) Str::uuid(), protocol: (string) Str::uuid());
    }

    private function isOfflineContingency(FiscalInvoicePayload $payload): bool
    {
        return $payload->kind === FiscalDocumentKind::NFCE && $payload->emissionMode === FiscalEmissionMode::OFFLINE_CONTINGENCY;
    }

    private function organizationStillInContingency(FiscalDocument $document): bool
    {
        return $document->organization_id !== null
            && (bool) Organization::query()->whereKey($document->organization_id)->value('fiscal_contingency_started_at');
    }

    /** Contingência ainda não tem protocolo: ele vem na transmissão. */
    private function protocolFor(FiscalDocumentStatus $status): ?string
    {
        return $status === FiscalDocumentStatus::AUTHORIZED ? (string) Str::uuid() : null;
    }

    /** NF-e/NFC-e: chave de 44 dígitos válida; NFS-e (chave própria do Emissor Nacional) segue simulada. */
    private function accessKey(FiscalDocument $document, FiscalInvoicePayload $payload): string
    {
        if ($document->kind === FiscalDocumentKind::NFSE) {
            return sprintf('LOG-%s-%s', $document->kind->value, (string) Str::uuid());
        }

        return FiscalAccessKey::compose([
            'state' => $payload->emitter->state(),
            'issued_at' => now(),
            'document' => $payload->emitter->document,
            'kind' => $document->kind,
            'series' => $payload->series,
            'number' => $payload->number,
            'mode' => $payload->emissionMode,
            'code' => random_int(1, self::RANDOM_CODE_MAX),
        ]);
    }
}
