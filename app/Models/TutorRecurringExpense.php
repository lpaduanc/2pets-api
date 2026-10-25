<?php

namespace App\Models;

use App\Enums\Wallet\ExpenseCategory;
use App\Enums\Wallet\ExpenseFrequency;
use App\Enums\Wallet\ExpensePaymentMethod;
use App\Enums\Wallet\ExpenseSubcategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Gasto que se repete sozinho (ração todo mês, banho a cada 15 dias). */
class TutorRecurringExpense extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'pet_id',
        'category',
        'subcategory',
        'description',
        'amount',
        'payment_method',
        'frequency',
        'next_due_on',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'subcategory' => ExpenseSubcategory::class,
            'payment_method' => ExpensePaymentMethod::class,
            'frequency' => ExpenseFrequency::class,
            'amount' => 'decimal:2',
            'next_due_on' => 'immutable_date',
            'active' => 'boolean',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(TutorExpense::class, 'recurring_expense_id');
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeDue(Builder $query, \DateTimeInterface $today): Builder
    {
        return $query->where('active', true)->whereDate('next_due_on', '<=', $today);
    }
}
