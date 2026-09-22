<?php

namespace App\Models;

use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Documento fiscal de uma venda — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md. Toda emissão/consulta/
 * cancelamento passa por `App\Services\Fiscal\FiscalDocumentService`, nunca escrita direta
 * fora dele (o `provider`/`access_key`/`status` têm que refletir exatamente o que o
 * `FiscalProviderGateway` respondeu).
 */
class FiscalDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'sale_id',
        'kind',
        'series',
        'number',
        'access_key',
        'status',
        'provider',
        'provider_id',
        'issued_at',
        'xml_path',
        'pdf_path',
        'rejection_reason',
        'total',
        'tax_breakdown',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => FiscalDocumentKind::class,
            'status' => FiscalDocumentStatus::class,
            'issued_at' => 'datetime',
            'total' => 'decimal:2',
            'tax_breakdown' => 'array',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (FiscalDocumentStatus $status): string => $status->value,
            array_filter(FiscalDocumentStatus::cases(), fn (FiscalDocumentStatus $status): bool => $status->isPending())
        ));
    }
}
