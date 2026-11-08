<?php

namespace App\Services\Fiscal\Sefaz;

use App\Enums\FiscalDocumentKind;
use Illuminate\Support\Facades\Log;
use NFePHP\DA\NFe\Danfce;
use NFePHP\DA\NFe\Danfe;

/**
 * DANFE (NF-e) e DANFCE (NFC-e) em PDF com o sped-da — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §8. Falha ao gerar o PDF NUNCA desfaz a autorização: o
 * documento continua autorizado com o XML guardado e o PDF fica nulo (regerável a partir do XML).
 */
final class DanfeRenderer
{
    public function render(string $xml, FiscalDocumentKind $kind, int $documentId): ?string
    {
        try {
            $renderer = $kind === FiscalDocumentKind::NFCE ? new Danfce($xml) : new Danfe($xml);
            $renderer->debugMode(false);

            return $renderer->render();
        } catch (\Throwable $failure) {
            Log::warning('fiscal.danfe_failed', ['fiscal_document_id' => $documentId, 'error' => $failure->getMessage()]);

            return null;
        }
    }
}
