<?php

namespace App\Contracts;

use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\Enums\FiscalDocumentKind;
use App\Models\FiscalDocument;

/**
 * Escolhe o `FiscalProviderGateway` POR DONO — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §6. Substitui o bind global único: cada organização
 * transmite à SEFAZ (A1 válido + CSC para NFC-e + configuração ligada) ou segue simulada.
 */
interface FiscalGatewayResolver
{
    /** Driver para um documento/inutilização NOVO deste dono e modelo. */
    public function forOwner(FiscalOwner $owner, FiscalDocumentKind $kind): FiscalProviderGateway;

    /**
     * Driver de um documento JÁ criado: o que ficou gravado em `provider` na criação. Documento
     * nunca troca de driver no meio do caminho (um pendente da SEFAZ jamais vira "autorizado"
     * pelo simulado porque o certificado venceu).
     */
    public function forDocument(FiscalDocument $document): FiscalProviderGateway;
}
