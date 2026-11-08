<?php

namespace App\Models;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentOperation;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalEmissionMode;
use App\Enums\FiscalEnvironment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Documento fiscal de uma venda, devolução de venda ou devolução de compra — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md e docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md. Toda emissão/consulta/cancelamento passa pelos services de
 * `App\Services\Fiscal`, nunca escrita direta fora deles (o `provider`/`access_key`/`status`
 * têm que refletir exatamente o que o `FiscalProviderGateway` respondeu).
 */
class FiscalDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'sale_id',
        'operation',
        'direction',
        'sale_return_id',
        'purchase_return_id',
        'referenced_document_id',
        'referenced_access_key',
        'kind',
        'environment',
        'emission_mode',
        'contingency_reason',
        'series',
        'number',
        'access_key',
        'status',
        'provider',
        'provider_id',
        'protocol',
        'cancellation_protocol',
        'cancellation_reason',
        'cancelled_at',
        'issued_at',
        'xml_path',
        'pdf_path',
        'rejection_reason',
        'total',
        'surcharge_amount',
        'tax_breakdown',
        'attempts',
        'next_retry_at',
        'last_error',
        'cancellation_xml_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => FiscalDocumentKind::class,
            'status' => FiscalDocumentStatus::class,
            'operation' => FiscalDocumentOperation::class,
            'direction' => FiscalDocumentDirection::class,
            'environment' => FiscalEnvironment::class,
            'emission_mode' => FiscalEmissionMode::class,
            'number' => 'integer',
            'attempts' => 'integer',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'total' => 'decimal:2',
            'surcharge_amount' => 'decimal:2',
            'tax_breakdown' => 'array',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function referencedDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referenced_document_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FiscalDocumentItem::class)->orderBy('line_number');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    /**
     * Emitido em contingência há mais tempo do que o prazo de transmissão
     * (`config('fiscal.contingency.max_hours')` — prazo legal por UF a confirmar com o contador).
     */
    public function isContingencyOverdue(): bool
    {
        return $this->status === FiscalDocumentStatus::CONTINGENCY
            && $this->created_at !== null
            && $this->created_at->lt(now()->subHours((int) config('fiscal.contingency.max_hours')));
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', FiscalDocumentStatus::valuesWhere(
            fn (FiscalDocumentStatus $status): bool => $status->isPending()
        ));
    }

    /** @param  Builder<self>  $query */
    public function scopeOperation(Builder $query, FiscalDocumentOperation $operation): Builder
    {
        return $query->where('operation', $operation->value);
    }

    /**
     * Documentos que ocupam a emissão do mesmo fato (`FiscalDocumentStatus::blocksReissue`).
     *
     * @param  Builder<self>  $query
     */
    public function scopeBlockingReissue(Builder $query): Builder
    {
        return $query->whereIn('status', FiscalDocumentStatus::valuesWhere(
            fn (FiscalDocumentStatus $status): bool => $status->blocksReissue()
        ));
    }
}
