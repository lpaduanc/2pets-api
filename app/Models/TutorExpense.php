<?php

namespace App\Models;

use App\Enums\Wallet\ExpenseCategory;
use App\Enums\Wallet\ExpensePaymentMethod;
use App\Enums\Wallet\ExpenseSubcategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Gasto que o tutor registrou na Carteira (à mão ou por recorrência). */
class TutorExpense extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'pet_id',
        'category',
        'subcategory',
        'description',
        'amount',
        'spent_on',
        'payment_method',
        'recurring_expense_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'subcategory' => ExpenseSubcategory::class,
            'payment_method' => ExpensePaymentMethod::class,
            'amount' => 'decimal:2',
            'spent_on' => 'immutable_date',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function recurringExpense(): BelongsTo
    {
        return $this->belongsTo(TutorRecurringExpense::class, 'recurring_expense_id');
    }

    /** Descrição que o tutor vê: a dele, ou o rótulo da subcategoria, ou o da categoria. */
    public function displayDescription(): string
    {
        return $this->description
            ?? $this->subcategory?->label()
            ?? $this->category->label();
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
