<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Linha de cobrança lançada durante o atendimento (a "conta" em aberto). Contrato
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.3 — pendurada no
 * AGENDAMENTO, não mais no prontuário: todo atendimento tem um agendamento, clínico ou
 * não (banho e tosa não gera `MedicalRecord`).
 */
class AppointmentCharge extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'appointment_id',
        'service_id',
        'description',
        'quantity',
        'unit_price',
        'added_by',
        'reference_date',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'reference_date' => 'date',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
