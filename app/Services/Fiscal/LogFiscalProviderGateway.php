<?php

namespace App\Services\Fiscal;

use App\Contracts\FiscalProviderGateway;
use App\DataTransferObjects\Fiscal\FiscalProviderResult;
use App\Enums\FiscalDocumentStatus;
use App\Models\FiscalDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Driver PADRÃO do ambiente — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md: "o ambiente sobe e a emissão
 * 'funciona' (grava documento com status simulado e loga o payload) sem nenhuma credencial
 * configurada". Nunca fala com SEFAZ/prefeitura de verdade.
 *
 * Autoriza IMEDIATAMENTE (sem fila): não há nada assíncrono para esconder atrás de um job
 * quando a "emissão" é só gerar uma chave e logar. Um driver real que precise de polling
 * implementa o mesmo contrato e devolve `PROCESSING` em `issue()` — a troca é só o bind em
 * `AppServiceProvider`, nunca um `if` aqui.
 */
final class LogFiscalProviderGateway implements FiscalProviderGateway
{
    public function issue(FiscalDocument $document): FiscalProviderResult
    {
        $accessKey = $this->fakeAccessKey($document);

        Log::info('fiscal.issue (driver=log, simulado)', [
            'fiscal_document_id' => $document->id,
            'sale_id' => $document->sale_id,
            'kind' => $document->kind->value,
            'total' => (float) $document->total,
            'access_key' => $accessKey,
        ]);

        return new FiscalProviderResult(FiscalDocumentStatus::AUTHORIZED, accessKey: $accessKey, providerId: $accessKey);
    }

    public function checkStatus(FiscalDocument $document): FiscalProviderResult
    {
        // O driver fake nunca deixa nada pendente — se chegou aqui já foi autorizado no
        // `issue()`. Devolve o estado atual sem mudar nada, para o comando de reconciliação
        // funcionar igual com qualquer driver.
        return new FiscalProviderResult($document->status, accessKey: $document->access_key, providerId: $document->provider_id);
    }

    public function cancel(FiscalDocument $document, string $reason): FiscalProviderResult
    {
        Log::info('fiscal.cancel (driver=log, simulado — regra real de janela legal depende do provedor)', [
            'fiscal_document_id' => $document->id,
            'reason' => $reason,
        ]);

        return new FiscalProviderResult(FiscalDocumentStatus::CANCELLED, protocol: (string) Str::uuid());
    }

    private function fakeAccessKey(FiscalDocument $document): string
    {
        return sprintf('LOG-%s-%s', $document->kind->value, (string) Str::uuid());
    }
}
