<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adendo a um prontuário — append-only por exigência da Res. CFMV nº 1.321/2020 alt.
 * 1.653/2025 (autoria nominal e voluntária de cada evolução, sem reescrever a anterior).
 *
 * Mesmo padrão write-once já usado em `ConsentLog`: sem `updated_at` (`$timestamps = false`
 * mais `creating()` para carimbar `created_at` sozinho), sem `deleted_at`, sem rota de
 * update/destroy em nenhum controller.
 */
class MedicalRecordAddendum extends Model
{
    public $timestamps = false;

    /** Eloquent pluralizaria "addendum" como "addendums" — a tabela usa o plural correto. */
    protected $table = 'medical_record_addenda';

    protected $fillable = [
        'medical_record_id',
        'author_id',
        'body',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $addendum): void {
            $addendum->created_at ??= now();
        });
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
