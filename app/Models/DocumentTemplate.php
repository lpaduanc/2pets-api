<?php

namespace App\Models;

use App\Enums\DocumentTemplateKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo de documento (atestado, termo, declaração) — contrato docs/gap-simplesvet/specs/
 * 15-modelos-documento-receituario-assinatura-spec.md. Editar `body_html` depois NUNCA
 * altera um `GeneratedDocument` já emitido (regra de negócio 3) — a cópia é congelada lá.
 */
class DocumentTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'kind',
        'body_html',
        'requires_signature',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DocumentTemplateKind::class,
            'requires_signature' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, ?int $organizationId): Builder
    {
        return $query->where(function (Builder $scoped) use ($organizationId): void {
            $scoped->whereNull('organization_id');

            if ($organizationId !== null) {
                $scoped->orWhere('organization_id', $organizationId);
            }
        });
    }
}
