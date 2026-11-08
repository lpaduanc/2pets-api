<?php

namespace App\Services\Fiscal;

use App\Contracts\FiscalProviderGateway;
use App\DataTransferObjects\Fiscal\FiscalProviderResult;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalEmissionMode;
use App\Exceptions\Fiscal\FiscalProviderUnavailable;
use App\Models\FiscalDocument;
use App\Models\Organization;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Leva um documento já persistido ao gateway e grava a resposta — spec docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §4 (máquina de estados). Único lugar que traduz
 * `FiscalProviderResult` em colunas e que trata `FiscalProviderUnavailable`: o documento fica
 * `pending` com tentativa, próxima data e erro — a venda nunca trava por falha de transporte.
 */
final class FiscalDocumentDispatcher
{
    private const ERROR_MAX_LENGTH = 500;

    private const HELD_BY_CONTINGENCY = 'Empresa em contingência: a NF-e será transmitida quando a SEFAZ voltar.';

    public function __construct(
        private readonly FiscalProviderGateway $gateway,
        private readonly FiscalInvoicePayloadBuilder $payloads,
    ) {}

    public function send(FiscalDocument $document): FiscalDocument
    {
        $organization = $this->organization($document);

        if ($organization?->isInFiscalContingency() && $document->kind === FiscalDocumentKind::NFE) {
            return $this->hold($document);
        }

        $this->switchToOfflineContingency($document, $organization);

        try {
            $result = $this->gateway->issue($document, $this->payloads->build($document));
        } catch (FiscalProviderUnavailable $unavailable) {
            return $this->registerFailure($document, $unavailable);
        }

        return $this->apply($document, $result);
    }

    /**
     * `POST fiscal-documents/{id}/sync` e reconciliação: pendente é reenviado; processando ou em
     * contingência é consultado; estado final não muda.
     */
    public function sync(FiscalDocument $document): FiscalDocument
    {
        if ($document->status === FiscalDocumentStatus::PENDING) {
            return $this->send($document);
        }

        if (! $document->status->awaitsProvider()) {
            return $document;
        }

        try {
            return $this->apply($document, $this->gateway->checkStatus($document));
        } catch (FiscalProviderUnavailable $unavailable) {
            return $this->registerFailure($document, $unavailable);
        }
    }

    public function apply(FiscalDocument $document, FiscalProviderResult $result): FiscalDocument
    {
        $document->update([
            'status' => $result->status->value,
            'provider' => $this->gateway->name(),
            'access_key' => $result->accessKey ?? $document->access_key,
            'provider_id' => $result->providerId ?? $document->provider_id,
            'protocol' => $result->protocol ?? $document->protocol,
            'rejection_reason' => $result->rejectionReason,
            'number' => $result->number ?? $document->number,
            'series' => $result->series ?? $document->series,
            'issued_at' => $result->status->countsAsIssued() ? ($document->issued_at ?? now()) : null,
            'next_retry_at' => null,
            'last_error' => $result->notice,
        ]);

        return $document->fresh();
    }

    /**
     * NFC-e enviada com a empresa em contingência — inclusive a pendente que falhou ANTES de a
     * contingência começar — sai offline (tpEmis 9) com o motivo informado na entrada.
     */
    private function switchToOfflineContingency(FiscalDocument $document, ?Organization $organization): void
    {
        if ($document->kind !== FiscalDocumentKind::NFCE || ! $organization?->isInFiscalContingency() || $document->emission_mode === FiscalEmissionMode::OFFLINE_CONTINGENCY) {
            return;
        }

        $document->update(['emission_mode' => FiscalEmissionMode::OFFLINE_CONTINGENCY->value, 'contingency_reason' => $organization->fiscal_contingency_reason]);
    }

    private function organization(FiscalDocument $document): ?Organization
    {
        return $document->organization_id === null ? null : Organization::find($document->organization_id);
    }

    /** NF-e com a empresa em contingência: não há SEFAZ para receber; espera e retenta. */
    private function hold(FiscalDocument $document): FiscalDocument
    {
        $document->update(['last_error' => self::HELD_BY_CONTINGENCY, 'next_retry_at' => now()->addMinutes(self::backoffMinutes(max(1, $document->attempts)))]);

        return $document->fresh();
    }

    private function registerFailure(FiscalDocument $document, FiscalProviderUnavailable $unavailable): FiscalDocument
    {
        $attempts = $document->attempts + 1;

        Log::warning('fiscal.provider_unavailable', [
            'fiscal_document_id' => $document->id,
            'sale_id' => $document->sale_id,
            'attempts' => $attempts,
            'error' => $unavailable->getMessage(),
        ]);

        $document->update([
            'attempts' => $attempts,
            'next_retry_at' => now()->addMinutes(self::backoffMinutes($attempts)),
            'last_error' => Str::limit($unavailable->getMessage(), self::ERROR_MAX_LENGTH - 3),
        ]);

        return $document->fresh();
    }

    /** Espera da N-ésima tentativa (`config('fiscal.retry.backoff_minutes')`), repetindo a última. */
    public static function backoffMinutes(int $attempts): int
    {
        $steps = (array) config('fiscal.retry.backoff_minutes');

        return (int) $steps[min($attempts, count($steps)) - 1];
    }
}
