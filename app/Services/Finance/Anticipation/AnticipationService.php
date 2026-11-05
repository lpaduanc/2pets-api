<?php

namespace App\Services\Finance\Anticipation;

use App\Enums\AnticipationMode;
use App\Enums\AnticipationStatus;
use App\Enums\ReceivableStatus;
use App\Models\FinancialAccount;
use App\Models\Receivable;
use App\Models\ReceivableAnticipation;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Antecipação de recebíveis (docs/gap-petflow/PLANO.md, 2.1).
 *
 *  - `simulate`: a conta (`AnticipationCalculator`) sobre parcelas `pending` do escopo, sem gravar.
 *  - `confirm`: em transação, TRAVA as parcelas (`lockForUpdate`) — duas antecipações simultâneas da
 *    mesma parcela: a segunda encontra `anticipated` e recebe 422. Só parcela `pending`, todas da
 *    mesma organização, com data da parcela ≥ data da antecipação. Marca `anticipated`, troca a
 *    data esperada pela da antecipação (a original fica no item, para o cancelamento) e lança a
 *    taxa como despesa financeira (`AnticipationLedger`).
 *  - `cancel`: só enquanto nenhuma parcela foi conciliada com depósito da adquirente — volta tudo a
 *    `pending` na data original e estorna a despesa.
 */
final class AnticipationService
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly AnticipationCalculator $calculator,
        private readonly AnticipationLedger $ledger,
    ) {}

    /**
     * @param  list<int>  $receivableIds
     * @return array<string, mixed>
     */
    public function simulate(User $user, array $receivableIds, string $date, AnticipationMode $mode, string $rate): array
    {
        $receivables = $this->scope->scopeQuery(Receivable::query(), $user)->whereIn('id', $receivableIds)->orderBy('expected_date')->orderBy('id')->get();

        return $this->calculate($receivables, $receivableIds, $date, $mode, $rate);
    }

    /** @param  array{receivable_ids: list<int>, anticipated_at: string, mode: string, rate: string|float, account_id: int}  $data */
    public function confirm(User $user, array $data): ReceivableAnticipation
    {
        $account = $this->scope->scopeQuery(FinancialAccount::query(), $user)->findOrFail((int) $data['account_id']);
        $mode = AnticipationMode::from($data['mode']);

        return DB::transaction(function () use ($user, $data, $account, $mode): ReceivableAnticipation {
            $receivables = $this->scope->scopeQuery(Receivable::query(), $user)->whereIn('id', $data['receivable_ids'])
                ->orderBy('expected_date')->orderBy('id')->lockForUpdate()->get();
            $result = $this->calculate($receivables, $data['receivable_ids'], $data['anticipated_at'], $mode, (string) $data['rate']);
            $anticipation = $this->store($user, $receivables, $result, $account);
            $this->ledger->recordFee($user, $anticipation);

            return $anticipation->fresh('items.receivable');
        });
    }

    public function cancel(User $user, ReceivableAnticipation $anticipation, ?string $reason): ReceivableAnticipation
    {
        abort_if($anticipation->status !== AnticipationStatus::CONFIRMED, 422, 'Esta antecipação já foi cancelada.');

        return DB::transaction(function () use ($user, $anticipation, $reason): ReceivableAnticipation {
            $receivables = Receivable::query()->whereIn('id', $anticipation->items()->select('receivable_id'))->lockForUpdate()->get();
            abort_if($receivables->contains(fn (Receivable $receivable): bool => $receivable->acquirer_settlement_id !== null || $receivable->status === ReceivableStatus::SETTLED), 422,
                'Esta antecipação já foi conciliada com o depósito da adquirente e não pode ser cancelada.');

            foreach ($anticipation->items as $item) {
                Receivable::query()->whereKey($item->receivable_id)->where('anticipation_id', $anticipation->id)->update([
                    'status' => ReceivableStatus::PENDING->value,
                    'expected_date' => $item->original_expected_date->toDateString(),
                    'anticipation_id' => null,
                ]);
            }

            $this->ledger->reverseFee($anticipation);
            $anticipation->update(['status' => AnticipationStatus::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancel_reason' => $reason]);

            return $anticipation->fresh('items.receivable');
        });
    }

    /**
     * @param  Collection<int, Receivable>  $receivables
     * @param  list<int>  $requestedIds
     * @return array<string, mixed>
     */
    private function calculate(Collection $receivables, array $requestedIds, string $date, AnticipationMode $mode, string $rate): array
    {
        $this->assertAnticipable($receivables, $requestedIds, CarbonImmutable::parse($date)->startOfDay());

        return $this->calculator->calculate($receivables->map(fn (Receivable $receivable): array => [
            'receivable_id' => $receivable->id,
            'net_amount' => (string) $receivable->net_amount,
            'expected_date' => $receivable->expected_date->toDateString(),
        ])->values()->all(), $date, $mode, $rate);
    }

    /**
     * @param  Collection<int, Receivable>  $receivables
     * @param  list<int>  $requestedIds
     */
    private function assertAnticipable(Collection $receivables, array $requestedIds, CarbonImmutable $date): void
    {
        $errors = match (true) {
            $receivables->count() !== count(array_unique($requestedIds)) => ['receivable_ids' => 'Parcela não encontrada nesta clínica.'],
            $receivables->contains(fn (Receivable $receivable): bool => $receivable->status !== ReceivableStatus::PENDING) => ['receivable_ids' => 'Só parcela a receber (pendente) pode ser antecipada; alguma já foi antecipada, recebida ou cancelada.'],
            $receivables->pluck('organization_id')->unique()->count() > 1 => ['receivable_ids' => 'As parcelas de uma antecipação têm de ser da mesma organização.'],
            $receivables->contains(fn (Receivable $receivable): bool => $receivable->expected_date->lt($date)) => ['anticipated_at' => 'A data da antecipação não pode ser depois da data de depósito de nenhuma parcela.'],
            default => [],
        };

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  Collection<int, Receivable>  $receivables
     * @param  array<string, mixed>  $result
     */
    private function store(User $user, Collection $receivables, array $result, FinancialAccount $account): ReceivableAnticipation
    {
        $first = $receivables->first();
        $anticipation = ReceivableAnticipation::create([
            'organization_id' => $first->organization_id,
            // Dono = o das parcelas; `professional_id` = quem antecipou (par padrão do comercial).
            'professional_id' => $user->id,
            'account_id' => $account->id,
            'anticipated_at' => $result['anticipated_at'],
            'mode' => $result['mode'],
            'rate' => $result['rate'],
            'gross_total' => $result['gross_total'],
            'fee_total' => $result['fee_total'],
            'net_total' => $result['net_total'],
            'status' => AnticipationStatus::CONFIRMED,
            'created_by' => $user->id,
        ]);

        foreach ($result['items'] as $line) {
            $anticipation->items()->create([
                'receivable_id' => $line['receivable_id'],
                'net_amount' => $line['net_amount'],
                'fee_amount' => $line['fee_amount'],
                'days' => $line['days'],
                'original_expected_date' => $line['expected_date'],
            ]);
            Receivable::query()->whereKey($line['receivable_id'])->update([
                'status' => ReceivableStatus::ANTICIPATED->value,
                'expected_date' => $result['anticipated_at'],
                'anticipation_id' => $anticipation->id,
            ]);
        }

        return $anticipation;
    }
}
