<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Pedido de exame como documento — contrato docs/gap-simplesvet/specs/16-modelos-exame-laudos-spec.md. */
class ExamRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'pet_id',
        'requested_by',
        'clinical_notes',
        'pdf_path',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsToMany<ExamType, $this> */
    public function examTypes(): BelongsToMany
    {
        return $this->belongsToMany(ExamType::class, 'exam_request_exam_type');
    }
}
