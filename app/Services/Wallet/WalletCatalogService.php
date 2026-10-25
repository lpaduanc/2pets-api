<?php

namespace App\Services\Wallet;

use App\Enums\Wallet\ExpenseCategory;
use App\Enums\Wallet\ExpenseFrequency;
use App\Enums\Wallet\ExpensePaymentMethod;
use App\Enums\Wallet\ExpenseSubcategory;
use App\Models\Pet;
use App\Models\TutorExpense;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Tudo que o formulário de gasto precisa para o tutor só tocar (`GET /wallet/catalog`).
 *
 * Além das opções fixas, devolve os ATALHOS do próprio tutor: os gastos que ele mais repete,
 * já com o último valor, pet e forma de pagamento. Quem compra a mesma ração todo mês lança
 * com um toque ("Ração · R$ 189,90 · Toddy") e só corrige o valor se mudou.
 */
class WalletCatalogService
{
    private const QUICK_PICKS = 6;

    /** Janela dos atalhos: hábito recente, não o que o tutor fazia há dois anos. */
    private const QUICK_PICK_DAYS = 180;

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $pets = Pet::where('user_id', $user->id)->orderBy('name')->get();

        return [
            'categories' => $this->categories(),
            'payment_methods' => $this->options(ExpensePaymentMethod::cases()),
            'frequencies' => $this->options(ExpenseFrequency::cases()),
            'pets' => $pets->map(fn (Pet $pet) => WalletPetSummary::from($pet))->all(),
            // Com um pet só, o app já deixa ele marcado e o campo nem precisa aparecer.
            'default_pet_id' => $pets->count() === 1 ? $pets->first()->id : null,
            'quick_picks' => $this->quickPicks($user),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function categories(): array
    {
        return array_map(fn (ExpenseCategory $category) => [
            'value' => $category->value,
            'label' => $category->label(),
            'icon' => $category->icon(),
            'subcategories' => array_map(fn (ExpenseSubcategory $subcategory) => [
                'value' => $subcategory->value,
                'label' => $subcategory->label(),
                'suggested_frequency' => $subcategory->suggestedFrequency()?->value,
            ], $category->subcategories()),
        ], ExpenseCategory::cases());
    }

    /**
     * @param  list<ExpensePaymentMethod|ExpenseFrequency>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(fn ($case) => ['value' => $case->value, 'label' => $case->label()], $cases);
    }

    /**
     * Agrupa por subcategoria + pet: "Ração do Toddy" e "Ração do Max" são atalhos
     * diferentes, porque costumam ter valores diferentes.
     *
     * @return list<array<string, mixed>>
     */
    private function quickPicks(User $user): array
    {
        $since = WalletCalendar::today()->subDays(self::QUICK_PICK_DAYS)->toDateString();

        return TutorExpense::query()
            ->ownedBy($user)
            ->with('pet')
            ->whereNotNull('subcategory')
            ->where('spent_on', '>=', $since)
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (TutorExpense $expense) => $expense->subcategory->value.'|'.($expense->pet_id ?? 'all'))
            ->sortByDesc(fn (Collection $group) => $group->count())
            ->take(self::QUICK_PICKS)
            ->map(fn (Collection $group) => $this->quickPick($group->first(), $group->count()))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function quickPick(TutorExpense $latest, int $timesUsed): array
    {
        return [
            'category' => $latest->category->value,
            'subcategory' => $latest->subcategory->value,
            'label' => $latest->subcategory->label(),
            'description' => $latest->description,
            'amount' => (float) $latest->amount,
            'pet' => $latest->pet ? WalletPetSummary::from($latest->pet) : null,
            'payment_method' => $latest->payment_method?->value,
            'times_used' => $timesUsed,
        ];
    }
}
