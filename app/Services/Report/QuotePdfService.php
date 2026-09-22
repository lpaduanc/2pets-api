<?php

namespace App\Services\Report;

use App\Models\Sale;
use App\Services\Commercial\QuoteIssuer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * PDF do orçamento — docs/gap-simplesvet/24-orcamentos.md ("PDF sai com cabeçalho da clínica,
 * dados do animal, itens, total e validade").
 *
 * Gravado no disco `local` (privado), nunca no `public`: o orçamento traz nome do tutor e do
 * animal e valor de procedimento, e só sai pelo endpoint autenticado. Cada versão tem o seu
 * arquivo — a v1 continua legível depois da revisão.
 */
final class QuotePdfService
{
    private const RELATIONS = ['items', 'client', 'pet', 'createdBy'];

    public function render(Sale $quote): string
    {
        $quote->loadMissing(self::RELATIONS);

        return Pdf::loadView('pdfs.quote', [
            'quote' => $quote,
            'issuer' => QuoteIssuer::for($quote),
            'status' => $quote->effectiveQuoteStatus(),
        ])->output();
    }

    /** Gera, grava e devolve o caminho relativo no disco `local`. */
    public function store(Sale $quote): string
    {
        $path = sprintf('quotes/%d/orcamento-%d-v%d.pdf', $quote->id, $quote->number ?? $quote->id, $quote->version);

        Storage::disk('local')->put($path, $this->render($quote));

        return $path;
    }

    public function filename(Sale $quote): string
    {
        return sprintf('orcamento-%d-v%d.pdf', $quote->number ?? $quote->id, $quote->version);
    }
}
