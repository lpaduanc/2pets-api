<?php

namespace App\Services\Fiscal\Nfse\Danfse;

use App\Services\Fiscal\Nfse\NfseDocumentReader;
use Barryvdh\DomPDF\Facade\Pdf;
use Com\Tecnick\Barcode\Barcode;
use Illuminate\Support\Facades\Log;

/**
 * DANFSe próprio em PDF — NT 008 v1.02 (spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §8). A API oficial de DANFSe foi suspensa em
 * 03/08/2026: o emissor gera o documento a partir do XML autorizado. A4 retrato, uma página, QR
 * Code de consulta pública. Falha ao gerar NUNCA desfaz a autorização (PDF nulo, XML guardado).
 */
final class DanfseRenderer
{
    private const PUBLIC_QUERY_URL = 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=';

    private const QR_MODULE_SIZE = -3;

    public function render(string $nfseXml, int $documentId): ?string
    {
        try {
            $view = (new DanfseView(new NfseDocumentReader($nfseXml)))->toArray();

            return Pdf::loadView('pdfs.danfse', ['danfse' => $view, 'qrCode' => $this->qrCode($view['identification']['access_key'])])
                ->setPaper('a4', 'portrait')
                ->output();
        } catch (\Throwable $failure) {
            Log::warning('fiscal.danfse_failed', ['fiscal_document_id' => $documentId, 'error' => $failure->getMessage()]);

            return null;
        }
    }

    /** QR Code em SVG embutido (data URI): o dompdf não busca nada remoto. */
    private function qrCode(string $accessKey): string
    {
        $svg = (new Barcode)->getBarcodeObj('QRCODE,M', self::PUBLIC_QUERY_URL.$accessKey, self::QR_MODULE_SIZE, self::QR_MODULE_SIZE, 'black')->getSvgCode();

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
