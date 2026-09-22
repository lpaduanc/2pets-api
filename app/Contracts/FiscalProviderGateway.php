<?php

namespace App\Contracts;

use App\DataTransferObjects\Fiscal\FiscalProviderResult;
use App\Models\FiscalDocument;

/**
 * Fronteira com o provedor de emissão fiscal (Focus NFe, eNotas, PlugNotas, NFE.io,
 * Tecnospeed...) — contrato docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md.
 *
 * Decisão de arquitetura da spec: **não** falar direto com SEFAZ/prefeitura. Toda
 * implementação honra o contrato inteiro (LSP) — o driver padrão do ambiente
 * (`App\Services\Fiscal\LogFiscalProviderGateway`) resolve tudo sozinho, sem credencial.
 */
interface FiscalProviderGateway
{
    /** Emite o documento. Driver fake: autoriza na hora com um `access_key` simulado. */
    public function issue(FiscalDocument $document): FiscalProviderResult;

    /** Consulta o estado no provedor — usado por um driver real assíncrono para reconciliar. */
    public function checkStatus(FiscalDocument $document): FiscalProviderResult;

    /**
     * Cancela dentro da janela legal (varia por UF/tipo — o provedor real valida; o driver
     * fake permite sempre, com aviso de que a regra real depende do provedor escolhido).
     */
    public function cancel(FiscalDocument $document, string $reason): FiscalProviderResult;
}
