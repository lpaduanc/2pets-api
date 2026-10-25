<?php

namespace App\Services\Wallet;

use App\Enums\InvoiceStatus;
use App\Enums\Wallet\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Pet;
use App\Models\TutorExpense;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Resumo da Carteira (`GET /wallet`).
 *
 * Todas as contas são somas e agrupamentos sobre os lançamentos do período, feitos aqui no
 * servidor: o app só exibe. O app antigo calculava no cliente a partir da lista, o que
 * obrigava a baixar todos os gastos do ano para mostrar um total.
 */
class WalletSummaryService
{
    /** Meses do gráfico de evolução, sempre terminando no mês do fim do período. */
    private const TREND_MONTHS = 6;

    /** @return array<string, mixed> */
    public function summarize(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $petId): array
    {
        $entries = $this->entries($user, $from, $to, $petId);
        [$previousFrom, $previousTo] = $this->previousRange($from, $to);
        $previousTotal = $this->total($this->entries($user, $previousFrom, $previousTo, $petId));
        $total = $this->total($entries);
        $yearEntries = $this->entries($user, $to->startOfYear(), $to, $petId);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'stats' => [
                'total' => $total,
                'count' => $entries->count(),
                'previous_total' => $previousTotal,
                'change_percent' => $this->changePercent($total, $previousTotal),
                'year_total' => $this->total($yearEntries),
                'monthly_average' => $this->monthlyAverage($yearEntries, $to),
                'top_category' => $this->byCategory($entries)->first()['category'] ?? null,
            ],
            'by_category' => $this->byCategory($entries)->values()->all(),
            'by_pet' => $this->byPet($entries)->values()->all(),
            'trend' => $this->trend($user, $to, $petId),
            'expenses' => $entries->map(fn (WalletEntry $entry) => $entry->toArray())->values()->all(),
        ];
    }

    /** @return Collection<int, WalletEntry> */
    public function entries(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $petId): Collection
    {
        return $this->expenseEntries($user, $from, $to, $petId)
            ->concat($this->invoiceEntries($user, $from, $to, $petId))
            ->sortByDesc(fn (WalletEntry $entry) => $entry->spentOn->toDateString().'-'.$entry->key)
            ->values();
    }

    /** @return Collection<int, WalletEntry> */
    private function expenseEntries(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $petId): Collection
    {
        return TutorExpense::query()
            ->ownedBy($user)
            ->with('pet')
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->when($petId, fn ($query) => $query->where('pet_id', $petId))
            ->get()
            ->map(fn (TutorExpense $expense) => WalletEntry::fromExpense($expense));
    }

    /**
     * Faturas pagas pelo 2pets. O pet vem do atendimento ou do prontuário da fatura, e só
     * conta se for do próprio tutor (fatura de pet transferido não aparece com o pet de outro).
     *
     * @return Collection<int, WalletEntry>
     */
    private function invoiceEntries(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $petId): Collection
    {
        $ownPets = Pet::where('user_id', $user->id)->get()->keyBy('id');

        return Invoice::query()
            ->with(['professional:id,name', 'appointment:id,pet_id', 'medicalRecord:id,pet_id'])
            ->where('client_id', $user->id)
            ->where('status', InvoiceStatus::PAID->value)
            ->whereRaw('COALESCE(payment_date, issue_date) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->map(function (Invoice $invoice) use ($ownPets) {
                $invoicePetId = $invoice->appointment?->pet_id ?? $invoice->medicalRecord?->pet_id;

                return WalletEntry::fromInvoice($invoice, $ownPets->get($invoicePetId));
            })
            ->when($petId, fn (Collection $entries) => $entries->filter(fn (WalletEntry $entry) => $entry->pet?->id === $petId));
    }

    /** @param Collection<int, WalletEntry> $entries */
    private function total(Collection $entries): float
    {
        return round($entries->sum(fn (WalletEntry $entry) => $entry->amount), 2);
    }

    /**
     * Média por mês no ano, contando a partir do mês do primeiro gasto: quem começou a usar
     * o app em setembro não tem a média de outubro dividida por dez meses vazios.
     *
     * @param  Collection<int, WalletEntry>  $yearEntries
     */
    private function monthlyAverage(Collection $yearEntries, CarbonImmutable $to): float
    {
        if ($yearEntries->isEmpty()) {
            return 0.0;
        }

        $firstMonth = $yearEntries->min(fn (WalletEntry $entry) => $entry->spentOn->month);

        return round($this->total($yearEntries) / ($to->month - $firstMonth + 1), 2);
    }

    private function changePercent(float $total, float $previousTotal): ?float
    {
        if ($previousTotal <= 0) {
            return null;
        }

        return round((($total - $previousTotal) / $previousTotal) * 100, 1);
    }

    /**
     * Período anterior equivalente. Janela de calendário (começa no dia 1) compara com os
     * mesmos dias dos meses anteriores: 1–25/out contra 1–25/set, e não contra setembro
     * inteiro, que sempre pareceria maior no meio do mês.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function previousRange(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($from->day === 1) {
            $months = (int) $from->diffInMonths($to->startOfMonth()) + 1;

            return [$from->subMonthsNoOverflow($months), $to->subMonthsNoOverflow($months)];
        }

        $days = (int) $from->diffInDays($to) + 1;

        return [$from->subDays($days), $to->subDays($days)];
    }

    /**
     * @param  Collection<int, WalletEntry>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function byCategory(Collection $entries): Collection
    {
        $total = $this->total($entries);

        return $entries
            ->groupBy(fn (WalletEntry $entry) => $entry->category->value)
            ->map(function (Collection $group, string $category) use ($total) {
                $amount = $this->total($group);
                $enum = ExpenseCategory::from($category);

                return [
                    'category' => $category,
                    'label' => $enum->label(),
                    'icon' => $enum->icon(),
                    'amount' => $amount,
                    'count' => $group->count(),
                    'percent' => $total > 0 ? (int) round($amount / $total * 100) : 0,
                ];
            })
            ->sortByDesc('amount');
    }

    /**
     * Gasto sem pet ("todos os pets") aparece como linha própria, `pet: null`, em vez de
     * ser rateado: dividir um saco de ração por igual entre um gato e um cachorro mentiria.
     *
     * @param  Collection<int, WalletEntry>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function byPet(Collection $entries): Collection
    {
        $total = $this->total($entries);

        return $entries
            ->groupBy(fn (WalletEntry $entry) => (string) ($entry->pet?->id ?? 'all'))
            ->map(function (Collection $group) use ($total) {
                $amount = $this->total($group);
                $pet = $group->first()->pet;

                return [
                    'pet' => $pet ? WalletPetSummary::from($pet) : null,
                    'amount' => $amount,
                    'percent' => $total > 0 ? (int) round($amount / $total * 100) : 0,
                ];
            })
            ->sortByDesc('amount');
    }

    /** @return list<array{month: string, amount: float}> */
    private function trend(User $user, CarbonImmutable $to, ?int $petId): array
    {
        $start = $to->startOfMonth()->subMonthsNoOverflow(self::TREND_MONTHS - 1);
        $byMonth = $this->entries($user, $start, $to->endOfMonth(), $petId)
            ->groupBy(fn (WalletEntry $entry) => $entry->spentOn->format('Y-m'));

        return collect(range(0, self::TREND_MONTHS - 1))
            ->map(function (int $offset) use ($start, $byMonth) {
                $month = $start->addMonthsNoOverflow($offset)->format('Y-m');

                return ['month' => $month, 'amount' => $this->total($byMonth->get($month, collect()))];
            })
            ->all();
    }
}
