<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'professional_id',
        'organization_id',
        'client_id',
        'appointment_id',
        'medical_record_id',
        'invoice_number',
        'issue_date',
        'due_date',
        'items',
        'subtotal',
        'discount',
        'tax',
        'total',
        'status',
        'payment_method',
        'payment_date',
        'payment_channel',
        'notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'payment_date' => 'date',
        'items' => 'array',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    /**
     * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md
     * §3-bis.3: inclui acerto final E adiantamentos — quem distingue os dois é
     * `payments.purpose`, não esta relação.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::DRAFT->value;
    }

    public function isPending(): bool
    {
        return $this->status === InvoiceStatus::PENDING->value;
    }

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::PAID->value;
    }

    /**
     * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §12.3:
     * `overdue` nunca é persistido — sempre calculado na leitura.
     */
    public function isOverdue(): bool
    {
        return $this->isPending() && $this->due_date !== null && $this->due_date->lt(now()->startOfDay());
    }

    /**
     * Saldo é sempre derivado em leitura, nunca uma coluna (contrato §3-bis.3, mesmo
     * princípio de `isOverdue()`). Soma acerto final E adiantamentos — para fatura sem
     * adiantamento, é sempre `0` (`pending`) ou igual a `total` (`paid`), igual a hoje.
     */
    public function amountPaid(): float
    {
        return round((float) $this->payments
            ->where('status', PaymentStatus::PAID->value)
            ->sum('amount'), 2);
    }

    public function balanceDue(): float
    {
        return max(0.0, round((float) $this->total - $this->amountPaid(), 2));
    }

    /** Só é maior que zero quando adiantamentos já ultrapassaram o total (contrato §3-bis.3). */
    public function creditBalance(): float
    {
        return max(0.0, round($this->amountPaid() - (float) $this->total, 2));
    }
}
