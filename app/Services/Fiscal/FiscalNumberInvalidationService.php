<?php

namespace App\Services\Fiscal;

use App\Contracts\FiscalProviderGateway;
use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalNumberInvalidationStatus;
use App\Exceptions\Fiscal\FiscalProviderUnavailable;
use App\Models\FiscalNumberInvalidation;
use App\Models\User;
use App\Services\Fiscal\Calculation\FiscalNumberGaps;
use Illuminate\Validation\ValidationException;

/**
 * Inutilização de faixa de NF-e/NFC-e e lacunas da numeração — spec docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §3.7. As regras da faixa são da classe pura `FiscalNumberGaps`;
 * aqui ficam o banco, a capacidade do driver e o resultado do gateway.
 */
final class FiscalNumberInvalidationService
{
    private const VIOLATION_MESSAGES = [
        FiscalNumberGaps::VIOLATION_ORDER => ['number_from', 'O número inicial deve ser maior que zero e menor ou igual ao final.'],
        FiscalNumberGaps::VIOLATION_REASON_LENGTH => ['reason', 'A justificativa deve ter de 15 a 255 caracteres.'],
        FiscalNumberGaps::VIOLATION_OVERLAP => ['number_from', 'A faixa se sobrepõe a uma inutilização pendente ou homologada.'],
        FiscalNumberGaps::VIOLATION_USED_NUMBER => ['number_from', 'A faixa contém número já usado em documento emitido.'],
    ];

    public function __construct(
        private readonly FiscalProviderGateway $gateway,
        private readonly FiscalNumberSequencer $sequencer,
        private readonly FiscalNumberGaps $rules,
    ) {}

    /** @param  array{kind: string, number_from: int, number_to: int, reason: string}  $data */
    public function create(FiscalOwner $owner, User $user, array $data): FiscalNumberInvalidation
    {
        abort_unless($this->gateway->capabilities()->invalidation, 422, 'O emissor fiscal configurado não faz inutilização de numeração.');

        $kind = FiscalDocumentKind::from($data['kind']);
        $series = $this->sequencer->seriesFor($owner, $kind);
        $this->assertValid($owner, $kind, $data);

        $invalidation = FiscalNumberInvalidation::create($owner->attributes() + [
            'user_id' => $user->id,
            'kind' => $kind->value,
            'series' => $series,
            'year' => (int) now()->format('Y'),
            'environment' => $this->sequencer->environmentFor($owner)->value,
            'number_from' => (int) $data['number_from'],
            'number_to' => (int) $data['number_to'],
            'reason' => trim($data['reason']),
            'status' => FiscalNumberInvalidationStatus::PENDING->value,
            'provider' => $this->gateway->name(),
        ]);

        return $this->transmit($invalidation);
    }

    /** @return array{kind: string, series: string, environment: string, start_number: int, next_number: int, gaps: list<array{from: int, to: int}>} */
    public function gaps(FiscalOwner $owner, FiscalDocumentKind $kind): array
    {
        $sequence = $this->sequencer->current($owner, $kind);

        return [
            'kind' => $kind->value,
            'series' => $sequence->series,
            'environment' => $sequence->environment->value,
            'start_number' => $sequence->start_number,
            'next_number' => $sequence->next_number,
            'gaps' => $this->rules->gaps($this->used($owner, $kind), $this->blockingRanges($owner, $kind), $sequence->start_number, $sequence->next_number),
        ];
    }

    /** @param  array{number_from: int, number_to: int, reason: string}  $data */
    private function assertValid(FiscalOwner $owner, FiscalDocumentKind $kind, array $data): void
    {
        $next = $this->sequencer->current($owner, $kind)->next_number;
        $violations = $this->rules->violations((int) $data['number_from'], (int) $data['number_to'], (string) $data['reason'], $this->used($owner, $kind), $this->blockingRanges($owner, $kind));
        $messages = [];

        foreach ($violations as $violation) {
            [$field, $message] = self::VIOLATION_MESSAGES[$violation];
            $messages[$field][] = $message;
        }

        // Número ainda não alcançado seria emitido depois pela sequência: só se inutiliza o que já ficou para trás.
        if ((int) $data['number_to'] >= $next) {
            $messages['number_to'][] = sprintf('Só é possível inutilizar números anteriores ao próximo a emitir (%d).', $next);
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    private function transmit(FiscalNumberInvalidation $invalidation): FiscalNumberInvalidation
    {
        try {
            $result = $this->gateway->invalidate($invalidation);
        } catch (FiscalProviderUnavailable) {
            // Fica pendente (ocupa a faixa); a tela permite pedir de novo quando a SEFAZ voltar.
            return $invalidation;
        }

        $authorized = $result->status === FiscalDocumentStatus::AUTHORIZED;
        $invalidation->update([
            'status' => ($authorized ? FiscalNumberInvalidationStatus::AUTHORIZED : FiscalNumberInvalidationStatus::REJECTED)->value,
            'protocol' => $result->protocol,
            'provider_id' => $result->providerId,
            'rejection_reason' => $result->rejectionReason,
            'invalidated_at' => $authorized ? now() : null,
        ]);

        return $invalidation->fresh();
    }

    /** @return list<int> */
    private function used(FiscalOwner $owner, FiscalDocumentKind $kind): array
    {
        return $this->sequencer->usedNumbers($owner, $kind, $this->sequencer->seriesFor($owner, $kind), $this->sequencer->environmentFor($owner));
    }

    /** @return list<array{0: int, 1: int}> */
    private function blockingRanges(FiscalOwner $owner, FiscalDocumentKind $kind): array
    {
        return $owner->scope(FiscalNumberInvalidation::query())
            ->blockingRange()
            ->where('kind', $kind->value)
            ->where('series', $this->sequencer->seriesFor($owner, $kind))
            ->where('environment', $this->sequencer->environmentFor($owner)->value)
            ->get(['number_from', 'number_to'])
            ->map(fn (FiscalNumberInvalidation $range): array => [$range->number_from, $range->number_to])
            ->all();
    }
}
