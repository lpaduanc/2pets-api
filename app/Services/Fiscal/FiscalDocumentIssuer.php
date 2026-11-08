<?php

namespace App\Services\Fiscal;

use App\Contracts\FiscalProviderGateway;
use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\Enums\FiscalDocumentOperation;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalEmissionMode;
use App\Models\FiscalDocument;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Cria um documento fiscal com os itens congelados, reserva o número e despacha — o mesmo
 * caminho para venda, devolução de venda e devolução de compra (spec docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §3). O total do documento é a SOMA dos itens: nunca diverge deles.
 *
 * Todo documento nasce em emissão normal; o despacho decide a contingência
 * (`FiscalDocumentDispatcher`).
 */
final class FiscalDocumentIssuer
{
    public function __construct(
        private readonly FiscalProviderGateway $gateway,
        private readonly FiscalNumberSequencer $sequencer,
        private readonly FiscalDocumentDispatcher $dispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  dono, `operation`, `kind` e vínculos (sale/return/referência)
     * @param  list<array<string, mixed>>  $rows  itens congelados
     */
    public function issue(array $attributes, array $rows): FiscalDocument
    {
        return DB::transaction(function () use ($attributes, $rows): FiscalDocument {
            $document = FiscalDocument::create($this->documentAttributes($attributes, $rows));
            $document->items()->createMany($rows);
            $this->sequencer->reserve($document);

            return $this->dispatcher->send($document->fresh());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function documentAttributes(array $attributes, array $rows): array
    {
        $owner = new FiscalOwner($attributes['organization_id'], (int) $attributes['professional_id']);
        $operation = FiscalDocumentOperation::from($attributes['operation']);

        return $attributes + [
            'emission_mode' => FiscalEmissionMode::NORMAL->value,
            // Quem vai transmitir fica gravado desde já: um pendente por queda do provedor também
            // mostra para onde será reenviado.
            'provider' => $this->gateway->name(),
            'direction' => $operation->direction()->value,
            'environment' => $this->sequencer->environmentFor($owner)->value,
            'status' => FiscalDocumentStatus::PENDING->value,
            'total' => Money::sum(array_map(fn (array $row): Money => Money::of($row['total']), $rows))->toString(),
        ];
    }
}
