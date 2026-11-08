<?php

namespace App\Contracts;

use App\DataTransferObjects\Fiscal\FiscalInvoicePayload;
use App\DataTransferObjects\Fiscal\FiscalProviderCapabilities;
use App\DataTransferObjects\Fiscal\FiscalProviderResult;
use App\Exceptions\Fiscal\FiscalProviderUnavailable;
use App\Models\FiscalDocument;
use App\Models\FiscalNumberInvalidation;

/**
 * Fronteira com quem transmite o documento fiscal — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md, revisado em
 * docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §0/§5.
 *
 * Decisão do produto (2026-11): NÃO haverá provedor terceiro. O driver real será nosso (assina com
 * o A1 da clínica e envia NF-e/NFC-e à SEFAZ, NFS-e ao Emissor Nacional) e implementa este mesmo
 * contrato; a troca é só o bind em `AppServiceProvider`. Até lá, o driver do ambiente é
 * `App\Services\Fiscal\LogFiscalProviderGateway` (simulado, sem credencial).
 *
 * Erros: falha de transporte lança `FiscalProviderUnavailable` (quem chama agenda nova tentativa);
 * rejeição de negócio volta como resultado `REJECTED`/`DENIED`. Toda implementação honra o
 * contrato inteiro (LSP) — o que não souber fazer declara em `capabilities()`.
 */
interface FiscalProviderGateway
{
    /** Identificador gravado em `fiscal_documents.provider` / `fiscal_number_invalidations.provider`. */
    public function name(): string;

    public function capabilities(): FiscalProviderCapabilities;

    /**
     * Transmite o documento (NF-e/NFC-e com o número já reservado por nós; NFS-e o provedor
     * numera). NFC-e com `emission_mode = offline_contingency` volta `CONTINGENCY`.
     *
     * @throws FiscalProviderUnavailable
     */
    public function issue(FiscalDocument $document, FiscalInvoicePayload $payload): FiscalProviderResult;

    /**
     * Consulta (e, para contingência, transmite) o estado no provedor — usado pela reconciliação.
     *
     * @throws FiscalProviderUnavailable
     */
    public function checkStatus(FiscalDocument $document): FiscalProviderResult;

    /**
     * Cancela dentro da janela legal (varia por UF/tipo — o driver real valida).
     *
     * @throws FiscalProviderUnavailable
     */
    public function cancel(FiscalDocument $document, string $reason): FiscalProviderResult;

    /**
     * Inutiliza a faixa de numeração junto à SEFAZ.
     *
     * @throws FiscalProviderUnavailable
     */
    public function invalidate(FiscalNumberInvalidation $invalidation): FiscalProviderResult;
}
