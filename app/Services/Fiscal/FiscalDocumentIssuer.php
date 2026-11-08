<?php

namespace App\Services\Fiscal;

use App\Contracts\FiscalGatewayResolver;
use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\Enums\FiscalDocumentKind;
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
 *
 * O despacho acontece DEPOIS do commit (spec fase4-transmissao-propria-nfe-nfce §7.3): com a SEFAZ
 * real, autorizar dentro de uma transação que depois desfaz deixaria uma nota autorizada na SEFAZ
 * sem registro aqui (e o número voltaria a ser usado). Também não segura o lock da sequência
 * durante a ida à SEFAZ.
 */
final class FiscalDocumentIssuer
{
    public function __construct(
        private readonly FiscalGatewayResolver $gateways,
        private readonly FiscalNumberSequencer $sequencer,
        private readonly FiscalDocumentDispatcher $dispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  dono, `operation`, `kind` e vínculos (sale/return/referência)
     * @param  list<array<string, mixed>>  $rows  itens congelados
     */
    public function issue(array $attributes, array $rows): FiscalDocument
    {
        return $this->dispatcher->send($this->create($attributes, $rows));
    }

    /**
     * Grava o documento pendente com os itens e o número reservado, sem despachar — quem emite
     * vários documentos de uma vez (venda mista) cria todos numa transação e despacha depois.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $rows
     */
    public function create(array $attributes, array $rows): FiscalDocument
    {
        return DB::transaction(function () use ($attributes, $rows): FiscalDocument {
            $document = FiscalDocument::create($this->documentAttributes($attributes, $rows));
            $document->items()->createMany($rows);
            $this->sequencer->reserve($document);

            return $document->fresh();
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
            // Quem vai transmitir fica gravado desde já e não muda mais (`forDocument`): um
            // pendente por queda da SEFAZ é reenviado para a SEFAZ, nunca "autorizado" pelo simulado.
            'provider' => $this->gateways->forOwner($owner, FiscalDocumentKind::from($attributes['kind']))->name(),
            'direction' => $operation->direction()->value,
            'environment' => $this->sequencer->environmentFor($owner)->value,
            'status' => FiscalDocumentStatus::PENDING->value,
            'total' => Money::sum(array_map(fn (array $row): Money => Money::of($row['total']), $rows))->toString(),
        ];
    }
}
