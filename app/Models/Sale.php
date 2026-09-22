<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalOperation;
use App\Enums\QuoteStatus;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Venda de balcão (ou orçamento, quando `kind = quote`) — contrato
 * docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * Não confundir com `Invoice` (fatura do atendimento veterinário) nem com `Order` (e-commerce):
 * as três coexistem de propósito, ver a migration.
 *
 * Toda mutação de valor passa por `App\Services\Commercial\SaleService`. Os totais aqui são
 * CACHE do que os itens somam — `recalculateTotals()` é o único lugar que os reescreve.
 */
class Sale extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'number',
        'client_id',
        'pet_id',
        'cash_register_id',
        'kind',
        'fiscal_operation',
        'status',
        'discount_type',
        'discount_value',
        'discount_amount',
        'subtotal',
        'total',
        'paid_amount',
        'printed_notes',
        'notes',
        'created_by',
        'sold_at',
        'cancelled_at',
        'cancellation_reason',
        'converted_to_sale_id',
        'valid_until',
        // Orçamento (doc 24).
        'quote_status',
        'sent_at',
        'viewed_at',
        'decided_at',
        'decided_by',
        'decision_channel',
        'decision_ip',
        'rejection_reason',
        'version',
        'parent_quote_id',
        'root_quote_id',
        'medical_record_id',
        'hospitalization_id',
        'pdf_path',
        'public_token_hash',
        'public_token_used_at',
    ];

    /** O hash do link público nunca sai em `toArray()`/JSON por acidente. */
    protected $hidden = ['public_token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SaleKind::class,
            'fiscal_operation' => FiscalOperation::class,
            'status' => SaleStatus::class,
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'sold_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'valid_until' => 'date',
            'quote_status' => QuoteStatus::class,
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'decided_at' => 'datetime',
            'public_token_used_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** Relações que a consulta de vendas e o recibo precisam para não cair em N+1. */
    public const RESOURCE_RELATIONS = [
        'items.staff.user',
        'receipts.paymentMethod',
        'client',
        'pet',
        'cashRegister',
        'createdBy',
        'fiscalDocuments',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Quem registrou (e dono, quando não há organização — vet volante). */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function convertedToSale(): BelongsTo
    {
        return $this->belongsTo(self::class, 'converted_to_sale_id');
    }

    /** A venda que nasceu deste orçamento — o lado inverso de `converted_to_sale_id`. */
    public function sourceQuote(): HasOne
    {
        return $this->hasOne(self::class, 'converted_to_sale_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function parentQuote(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_quote_id');
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function hospitalization(): BelongsTo
    {
        return $this->belongsTo(Hospitalization::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(SaleReceipt::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashRegisterMovement::class, 'reference_id')
            ->where('reference_type', self::class);
    }

    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }

    /**
     * Recalcula subtotal, desconto e total a partir dos ITENS — nunca a partir do que já
     * estava gravado. Chamado por `SaleService` depois de toda mudança de item ou desconto.
     *
     * O desconto percentual é reaplicado sobre o subtotal novo: adicionar um item a uma venda
     * com 10% de desconto tem que dar 10% sobre o total maior, e é por isso que o TIPO fica
     * gravado ao lado do valor (ver `App\Enums\DiscountType`).
     */
    public function recalculateTotals(): void
    {
        $subtotal = round((float) $this->items()->sum('total'), 2);
        $discount = $this->discount_type->amountFor($subtotal, (float) $this->discount_value);

        $this->subtotal = $subtotal;
        $this->discount_amount = $discount;
        $this->total = round($subtotal - $discount, 2);
    }

    public function amountDue(): float
    {
        return round((float) $this->total - (float) $this->paid_amount, 2);
    }

    public function isFullyPaid(): bool
    {
        // Um centavo de tolerância: parcelamento em 3x de R$ 100,00 dá 33,33 × 3 = 99,99.
        return $this->amountDue() <= 0.01;
    }

    public function isQuote(): bool
    {
        return $this->kind === SaleKind::QUOTE;
    }

    /**
     * Aceita item, desconto ou troca de cabeçalho? Venda: pelo `status`. Orçamento: também
     * precisa estar em rascunho — aprovado é imutável e mudança exige revisão (doc 24).
     * Único lugar da regra; `SaleService`, `SalePolicy` e os resources leem daqui.
     */
    public function isEditable(): bool
    {
        if (! $this->status->isEditable()) {
            return false;
        }

        return ! $this->isQuote() || $this->effectiveQuoteStatus()?->isEditable() === true;
    }

    /** `valid_until` já passou? Sem validade definida, o orçamento não vence. */
    public function isPastValidity(): bool
    {
        return $this->valid_until !== null && $this->valid_until->lt(today());
    }

    /**
     * Status que o orçamento TEM de fato hoje: rascunho/enviado/visualizado com validade
     * vencida é `EXPIRED`, sem precisar de job gravando isso (critério de aceite do doc 24).
     */
    public function effectiveQuoteStatus(): ?QuoteStatus
    {
        if ($this->quote_status === null) {
            return null;
        }

        return $this->quote_status->canExpire() && $this->isPastValidity()
            ? QuoteStatus::EXPIRED
            : $this->quote_status;
    }

    /** Raiz da família de versões (a v1). */
    public function rootQuoteId(): int
    {
        return $this->root_quote_id ?? $this->id;
    }

    /**
     * Filtro pelo status EFETIVO — a mesma regra de `effectiveQuoteStatus()`, em SQL. Filtrar
     * por "enviado" não pode trazer enviado vencido, e "expirado" precisa achar os vencidos
     * mesmo que a coluna ainda diga `sent`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWhereQuoteStatus(Builder $query, QuoteStatus $status): Builder
    {
        $table = $this->getTable();

        if ($status === QuoteStatus::EXPIRED) {
            return $query->where(fn (Builder $q) => $q
                ->where("{$table}.quote_status", QuoteStatus::EXPIRED->value)
                ->orWhere(fn (Builder $vencido) => $vencido
                    ->whereIn("{$table}.quote_status", QuoteStatus::expirableValues())
                    ->whereDate("{$table}.valid_until", '<', today())));
        }

        $query->where("{$table}.quote_status", $status->value);

        if ($status->canExpire()) {
            $query->where(fn (Builder $q) => $q
                ->whereNull("{$table}.valid_until")
                ->orWhereDate("{$table}.valid_until", '>=', today()));
        }

        return $query;
    }

    /** @param  Builder<self>  $query */
    public function scopeSalesOnly(Builder $query): Builder
    {
        return $query->where('kind', SaleKind::SALE->value);
    }

    /** @param  Builder<self>  $query */
    public function scopeQuotesOnly(Builder $query): Builder
    {
        return $query->where('kind', SaleKind::QUOTE->value);
    }

    /**
     * Documentos fiscais que esta venda ainda precisa ter emitidos — filtro "Pendência de
     * NFC-e / NF-e / NFS-e" da consulta de vendas (doc 01).
     *
     * Só venda PAGA gera obrigação de emitir. Um documento AUTORIZADO (`fiscalDocuments`, doc
     * 05) já cumpriu a obrigação daquele tipo — venda com NFC-e autorizada não aparece mais
     * como pendente só porque ainda tem item de produto. `REJECTED`/`DENIED`/`CANCELLED` não
     * contam como emitido: a venda continua precisando de uma emissão que vingue.
     * Exige `items` e `fiscalDocuments` carregados (vêm em `RESOURCE_RELATIONS`).
     *
     * @return list<string> subconjunto ordenado de ['nfce', 'nfe', 'nfse']
     */
    public function fiscalPendingDocuments(): array
    {
        if ($this->status !== SaleStatus::PAID || $this->isQuote()) {
            return [];
        }

        $types = $this->items->pluck('sellable_type')->unique();
        $issuedKinds = $this->issuedFiscalDocumentKinds();
        $pending = [];

        if ($types->contains(Product::class) && ! $issuedKinds->contains($this->fiscal_operation->productDocument())) {
            $pending[] = $this->fiscal_operation->productDocument();
        }

        if ($types->contains(Service::class) && ! $issuedKinds->contains('nfse')) {
            $pending[] = 'nfse';
        }

        return $pending;
    }

    /**
     * @return Collection<int, string>
     */
    private function issuedFiscalDocumentKinds(): Collection
    {
        return $this->fiscalDocuments
            ->where('status', FiscalDocumentStatus::AUTHORIZED)
            ->map(fn (FiscalDocument $document): string => $document->kind->value)
            ->values();
    }

    /**
     * Mesma regra de `fiscalPendingDocuments()`, em SQL, para o filtro da consulta. Uma venda
     * só some da pendência de um documento quando já tem `FiscalDocument` daquele `kind` com
     * status `authorized` — `REJECTED`/`DENIED`/`CANCELLED` mantém a venda pendente.
     * `none` = nada a emitir (não paga, orçamento ou sem itens).
     *
     * @param  Builder<self>  $query
     */
    public function scopeFiscalPending(Builder $query, string $document): Builder
    {
        $paidSale = fn (Builder $q): Builder => $q
            ->where('status', SaleStatus::PAID->value)
            ->where('kind', SaleKind::SALE->value);

        $withoutAuthorizedDocument = fn (Builder $q, string $kind): Builder => $q->whereDoesntHave(
            'fiscalDocuments',
            fn (Builder $documents) => $documents
                ->where('kind', $kind)
                ->where('status', FiscalDocumentStatus::AUTHORIZED->value)
        );

        return match ($document) {
            'nfce' => $withoutAuthorizedDocument($paidSale($query), 'nfce')
                ->whereIn('fiscal_operation', FiscalOperation::consumerInvoiceValues())
                ->whereHas('items', fn (Builder $items) => $items->where('sellable_type', Product::class)),
            'nfe' => $withoutAuthorizedDocument($paidSale($query), 'nfe')
                ->whereNotIn('fiscal_operation', FiscalOperation::consumerInvoiceValues())
                ->whereHas('items', fn (Builder $items) => $items->where('sellable_type', Product::class)),
            'nfse' => $withoutAuthorizedDocument($paidSale($query), 'nfse')
                ->whereHas('items', fn (Builder $items) => $items->where('sellable_type', Service::class)),
            default => $query->where(fn (Builder $q) => $q
                ->where('status', '!=', SaleStatus::PAID->value)
                ->orWhere('kind', SaleKind::QUOTE->value)
                ->orWhereDoesntHave('items')),
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'quote_status', 'total', 'discount_amount', 'client_id', 'cancelled_at', 'decided_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
