<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Um item do catálogo contratado num agendamento — pivô que substitui a FK única
 * `appointments.service_id` (deprecada, não removida) para comportar "consulta +
 * vacina + banho ao mesmo tempo". Contrato
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.2.
 *
 * `unit_price` é SNAPSHOT do preço no momento do agendamento (invariante 12) — nunca
 * referência viva ao catálogo: se `services.price` subir depois, o que já foi
 * combinado com o cliente não muda.
 */
class AppointmentService extends Model
{
    use SoftDeletes;

    protected $table = 'appointment_services';

    protected $fillable = [
        'appointment_id',
        'service_id',
        'quantity',
        'unit_price',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
