<?php

namespace App\Models;

use App\Exceptions\Hospitalization\HospitalizationProgressNoteImmutableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evolução diária da internação — contrato
 * docs/atendimento-veterinario/12-modulo-clinico-internacao.md §1. Append-only, mesmo padrão
 * de `MedicalRecordAddendum`: sem `updated_at`, sem `deleted_at`, sem rota de update/destroy
 * em nenhum controller.
 *
 * A imutabilidade NÃO depende só da rota ausente: `booted()` trava `updating`/`deleting` no
 * próprio model, para que nenhum caminho de escrita futuro (tinker, job, outro service)
 * consiga reescrever ou apagar uma entrada já gravada. Correção é uma entrada NOVA com
 * `corrects_id` apontando para a original, que nunca desaparece do histórico.
 *
 * Nenhum campo de sinal vital é obrigatório (§1.1) — só `body`, `author_id` e `recorded_at`,
 * os dois últimos preenchidos pelo sistema quando ausentes.
 */
class HospitalizationProgressNote extends Model
{
    public $timestamps = false;

    /**
     * Relações exigidas por `HospitalizationProgressNoteResource` (via `VetContactResource`).
     *
     * @var list<string>
     */
    public const RESOURCE_RELATIONS = ['author.professional'];

    protected $fillable = [
        'hospitalization_id',
        'author_id',
        'recorded_at',
        'body',
        'temperature',
        'heart_rate',
        'respiratory_rate',
        'weight',
        'corrects_id',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'created_at' => 'datetime',
            'temperature' => 'decimal:1',
            'weight' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $note): void {
            $note->created_at ??= now();
            $note->recorded_at ??= now();
        });

        static::updating(function (): void {
            throw HospitalizationProgressNoteImmutableException::forUpdate();
        });

        static::deleting(function (): void {
            throw HospitalizationProgressNoteImmutableException::forDelete();
        });
    }

    public function hospitalization(): BelongsTo
    {
        return $this->belongsTo(Hospitalization::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** A entrada original que esta corrige, quando presente (§1.3). */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_id');
    }
}
