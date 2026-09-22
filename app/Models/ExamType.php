<?php

namespace App\Models;

use App\Enums\ExamTypeCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catálogo de exame da clínica — contrato docs/gap-simplesvet/specs/16-modelos-exame-laudos-spec.md.
 * Nunca bloqueia a criação de um `Exam` (regra de negócio 1) — é conveniência de
 * padronização, `Exam::exam_type_id` é sempre nullable.
 */
class ExamType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'category',
        'presentation_html',
        'closing_html',
        'preparation_instructions',
        'service_id',
        'default_duration_minutes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'category' => ExamTypeCategory::class,
            'default_duration_minutes' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** Corpo pré-montado do laudo (apresentação + encerramento) — regra de negócio 2. */
    public function draftReportHtml(): string
    {
        return trim(($this->presentation_html ?? '')."\n\n".($this->closing_html ?? ''));
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
