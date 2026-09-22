<?php

namespace App\Services\Reports;

use App\DataTransferObjects\Reports\BirthdayFilters;
use App\Models\Pet;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Professional\ProfessionalClientsQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Painel de aniversariantes (pet e tutor) — contrato
 * `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`, regra de negócio 3. Cobre quem
 * só informou dia/mês (`birthday_month`/`birthday_day`) além de quem tem `birth_date` completo.
 *
 * **29/02 em ano não bissexto:** decisão de produto explícita — aparece no dia 28/02 (convenção
 * mais comum em sistemas de calendário brasileiros), nunca 01/03. Fixado aqui, não deixado
 * implícito.
 */
final class BirthdayService
{
    private const FEBRUARY = 2;

    private const LEAP_DAY = 29;

    private const LEAP_DAY_FALLBACK = 28;

    public function __construct(
        private readonly CommercialScopeResolver $scopeResolver,
        private readonly ProfessionalClientsQuery $clientsQuery,
    ) {}

    /**
     * @return array{pets: Collection<int, array<string, mixed>>, clients: Collection<int, array<string, mixed>>}
     */
    public function inPeriod(User $professional, BirthdayFilters $filters): array
    {
        $clientIds = $this->clientIdsFor($professional);
        $window = $this->monthDayWindow($filters->from, $filters->to);

        return [
            'pets' => $filters->scope === 'clients' ? collect() : $this->petsInWindow($clientIds, $window, $filters->includeContact),
            'clients' => $filters->scope === 'pets' ? collect() : $this->clientsInWindow($clientIds, $window, $filters->includeContact),
        ];
    }

    /** @return Collection<int, int> */
    private function clientIdsFor(User $professional): Collection
    {
        $teamUserIds = $this->scopeResolver->teamUserIds($professional);

        return $this->clientsQuery->queryForAny($teamUserIds)->pluck('id');
    }

    /**
     * @param  Collection<int, int>  $clientIds
     * @return Collection<int, array<string, mixed>>
     */
    private function petsInWindow(Collection $clientIds, array $window, bool $includeContact): Collection
    {
        return Pet::query()
            ->whereIn('user_id', $clientIds)
            ->where(fn (Builder $query) => $this->constrainToWindow($query, $window))
            ->with('user:id,name,phone,email')
            ->get()
            ->map(fn (Pet $pet): array => [
                'type' => 'pet',
                'id' => $pet->id,
                'name' => $pet->name,
                'birthday' => $this->birthdayLabel($pet->birth_date, $pet->birthday_month, $pet->birthday_day),
                'tutor' => $this->contactBlock($pet->user, $includeContact),
            ]);
    }

    /**
     * @param  Collection<int, int>  $clientIds
     * @return Collection<int, array<string, mixed>>
     */
    private function clientsInWindow(Collection $clientIds, array $window, bool $includeContact): Collection
    {
        return User::query()
            ->whereIn('id', $clientIds)
            ->where(fn (Builder $query) => $this->constrainToWindow($query, $window))
            ->get()
            ->map(fn (User $client): array => array_merge(
                ['type' => 'client', 'birthday' => $this->birthdayLabel($client->birth_date, $client->birthday_month, $client->birthday_day)],
                $this->contactBlock($client, $includeContact),
            ));
    }

    /**
     * Bloco de contato — regra de negócio 4 da spec: telefone/e-mail só aparecem para quem
     * tem `clients.contact.view-bulk`; sem a permissão, só id/nome, mesmo que o registro exista.
     *
     * @return array{id: ?int, name: ?string, phone?: ?string, email?: ?string}
     */
    private function contactBlock(?User $person, bool $includeContact): array
    {
        $block = ['id' => $person?->id, 'name' => $person?->name];

        if (! $includeContact) {
            return $block;
        }

        return $block + ['phone' => $person?->phone, 'email' => $person?->email];
    }

    /**
     * `birth_date` completo compara `TO_CHAR(birth_date, 'MM-DD')`; sem ele, cai no par
     * `birthday_month`/`birthday_day` formatado do mesmo jeito. Comparação por STRING (não
     * `EXTRACT` mês+dia separados) para o intervalo funcionar corretamente quando a janela
     * atravessa o fim de um mês (ex.: semana de 28/02 a 06/03) — índice funcional dedicado
     * continua cobrindo isto (ver a migration, `EXTRACT` é usado só no predicado do índice).
     *
     * @param  array{from: string, to: string, wraps_year: bool}  $window
     */
    private function constrainToWindow(Builder $query, array $window): void
    {
        $query->where(function (Builder $scoped) use ($window): void {
            $scoped->where(fn (Builder $q) => $this->applyMonthDayRange($q, "to_char(birth_date, 'MM-DD')", $window))
                ->orWhere(fn (Builder $q) => $this->applyFallbackRange($q, $window));
        });
    }

    /** @param  array{from: string, to: string, wraps_year: bool}  $window */
    private function applyFallbackRange(Builder $query, array $window): void
    {
        $query->whereNull('birth_date')
            ->whereNotNull('birthday_month')
            ->where(fn (Builder $q) => $this->applyMonthDayRange(
                $q,
                "lpad(birthday_month::text, 2, '0') || '-' || lpad(birthday_day::text, 2, '0')",
                $window,
            ));
    }

    /**
     * Regra de negócio 3: um aniversariante de 29/02 aparece em ano não bissexto no dia 28/02
     * — a janela que cobre 28/02 num ano corrente não-bissexto precisa casar com '02-29'
     * também, senão essa pessoa nunca aparece em nenhum painel daquele ano.
     *
     * @param  array{from: string, to: string, wraps_year: bool}  $window
     */
    private function applyMonthDayRange(Builder $query, string $expression, array $window): void
    {
        $query->where(function (Builder $scoped) use ($expression, $window): void {
            if ($window['wraps_year']) {
                $scoped->whereRaw("{$expression} >= ?", [$window['from']])
                    ->orWhereRaw("{$expression} <= ?", [$window['to']]);
            } else {
                $scoped->whereRaw("{$expression} BETWEEN ? AND ?", [$window['from'], $window['to']]);
            }

            if ($this->windowCoversLeapDayFallback($window)) {
                $scoped->orWhereRaw("{$expression} = '02-29'");
            }
        });
    }

    /** @param  array{from: string, to: string, wraps_year: bool}  $window */
    private function windowCoversLeapDayFallback(array $window): bool
    {
        if (now()->isLeapYear() || $window['wraps_year']) {
            return false;
        }

        return $window['from'] <= '02-28' && $window['to'] >= '02-28';
    }

    /**
     * @return array{from: string, to: string, wraps_year: bool}
     */
    private function monthDayWindow(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $fromKey = $from->format('m-d');
        $toKey = $to->format('m-d');

        return [
            'from' => $fromKey,
            'to' => $toKey,
            'wraps_year' => $fromKey > $toKey,
        ];
    }

    /**
     * `29/02` em ano corrente não bissexto vira `28/02` — regra de negócio 3. `birth_date` já
     * cravado como 29/02 (ano de nascimento bissexto) só precisa de ajuste na EXIBIÇÃO do
     * próximo aniversário, nunca na coluna gravada.
     */
    private function birthdayLabel(?\DateTimeInterface $birthDate, ?int $month, ?int $day): string
    {
        $rawMonth = $birthDate !== null ? (int) $birthDate->format('n') : $month;
        $rawDay = $birthDate !== null ? (int) $birthDate->format('j') : $day;

        if ($rawMonth === self::FEBRUARY && $rawDay === self::LEAP_DAY && ! now()->isLeapYear()) {
            $rawDay = self::LEAP_DAY_FALLBACK;
        }

        return sprintf('%02d/%02d', $rawDay, $rawMonth);
    }
}
