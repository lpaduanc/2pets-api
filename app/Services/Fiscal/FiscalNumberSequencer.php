<?php

namespace App\Services\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalEnvironment;
use App\Models\FiscalDocument;
use App\Models\FiscalNumberSequence;
use App\Models\Organization;

/**
 * Numeração própria de NF-e/NFC-e — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §3.7.
 * A reserva trava a linha da sequência (`lockForUpdate`) na mesma transação da emissão: dois
 * caixas emitindo ao mesmo tempo nunca pegam o mesmo número (e o índice único parcial de
 * `fiscal_documents` é a rede de segurança). NFS-e não passa aqui — numera o Emissor Nacional.
 */
final class FiscalNumberSequencer
{
    private const DEFAULT_SERIES = '1';

    private const NUMBERED_KINDS = [FiscalDocumentKind::NFE, FiscalDocumentKind::NFCE];

    public static function isNumbered(FiscalDocumentKind $kind): bool
    {
        return in_array($kind, self::NUMBERED_KINDS, true);
    }

    /** Grava série e número no documento ainda sem número. */
    public function reserve(FiscalDocument $document): void
    {
        if (! self::isNumbered($document->kind) || $document->number !== null) {
            return;
        }

        $owner = FiscalOwner::of($document);
        $sequence = $this->lockedSequence($owner, $document->kind, $this->seriesFor($owner, $document->kind), $document->environment);
        $document->update(['series' => $sequence->series, 'number' => $sequence->next_number]);
        $sequence->increment('next_number');
    }

    public function environmentFor(FiscalOwner $owner): FiscalEnvironment
    {
        $organization = $this->organization($owner);

        return $organization?->fiscal_environment ?? FiscalEnvironment::HOMOLOGATION;
    }

    public function seriesFor(FiscalOwner $owner, FiscalDocumentKind $kind): string
    {
        $organization = $this->organization($owner);
        $column = $kind === FiscalDocumentKind::NFCE ? 'nfce_series' : 'nfe_series';

        return (string) ($organization?->{$column} ?? self::DEFAULT_SERIES);
    }

    /** Sequência atual (sem travar) para a tela de configuração; inexistente = começa no 1. */
    public function current(FiscalOwner $owner, FiscalDocumentKind $kind): FiscalNumberSequence
    {
        $series = $this->seriesFor($owner, $kind);
        $environment = $this->environmentFor($owner);

        return $this->query($owner, $kind, $series, $environment)->first()
            ?? new FiscalNumberSequence($owner->attributes() + ['kind' => $kind, 'series' => $series, 'environment' => $environment, 'start_number' => 1, 'next_number' => 1]);
    }

    /**
     * Próximo número escolhido pelo dono (quem vem de outro sistema continua a numeração). Nunca
     * igual ou abaixo de um número já usado nesta série/ambiente — duplicaria documento.
     */
    public function configure(FiscalOwner $owner, FiscalDocumentKind $kind, int $nextNumber): void
    {
        $series = $this->seriesFor($owner, $kind);
        $environment = $this->environmentFor($owner);
        $sequence = $this->lockedSequence($owner, $kind, $series, $environment);
        $maxUsed = max([0, ...$this->usedNumbers($owner, $kind, $series, $environment)]);

        abort_if($nextNumber <= $maxUsed, 422, sprintf('O próximo número da %s precisa ser maior que %d, o último já usado na série %s.', $kind->label(), $maxUsed, $series));

        $sequence->update(['next_number' => $nextNumber, 'start_number' => $maxUsed === 0 ? $nextNumber : $sequence->start_number]);
    }

    /**
     * Números que a SEFAZ considera consumidos nesta série (todo status menos rejeitado).
     *
     * @return list<int>
     */
    public function usedNumbers(FiscalOwner $owner, FiscalDocumentKind $kind, string $series, ?FiscalEnvironment $environment): array
    {
        return $owner->scope(FiscalDocument::query())
            ->where('kind', $kind->value)
            ->where('series', $series)
            ->where('environment', $environment?->value)
            ->whereNotNull('number')
            ->whereIn('status', FiscalDocumentStatus::valuesWhere(fn (FiscalDocumentStatus $status): bool => $status->consumesNumber()))
            ->pluck('number')
            ->map(fn ($number): int => (int) $number)
            ->all();
    }

    private function lockedSequence(FiscalOwner $owner, FiscalDocumentKind $kind, string $series, ?FiscalEnvironment $environment): FiscalNumberSequence
    {
        $environment ??= FiscalEnvironment::HOMOLOGATION;
        $existing = $this->query($owner, $kind, $series, $environment)->lockForUpdate()->first();

        if ($existing !== null) {
            return $existing;
        }

        // `insertOrIgnore` (ON CONFLICT DO NOTHING): dois caixas criando a primeira linha juntos
        // não estouram o índice único — um insere, os dois travam a mesma linha a seguir.
        FiscalNumberSequence::query()->insertOrIgnore($owner->attributes() + [
            'kind' => $kind->value, 'series' => $series, 'environment' => $environment->value,
            'start_number' => 1, 'next_number' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->query($owner, $kind, $series, $environment)->lockForUpdate()->firstOrFail();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<FiscalNumberSequence> */
    private function query(FiscalOwner $owner, FiscalDocumentKind $kind, string $series, FiscalEnvironment $environment): \Illuminate\Database\Eloquent\Builder
    {
        return $owner->scope(FiscalNumberSequence::query())
            ->where('kind', $kind->value)
            ->where('series', $series)
            ->where('environment', $environment->value);
    }

    private function organization(FiscalOwner $owner): ?Organization
    {
        return $owner->organizationId === null ? null : Organization::find($owner->organizationId);
    }
}
