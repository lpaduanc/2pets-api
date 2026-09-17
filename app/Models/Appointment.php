<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'professional_id',
        'client_id',
        'pet_id',
        'service_id',
        'appointment_date',
        'appointment_time',
        'duration',
        'type',
        'status',
        'reason',
        'notes',
        'price',
        'booking_source',
        'requires_confirmation',
        'confirmed_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'appointment_date' => 'datetime',
        'appointment_time' => 'datetime:H:i',
        'duration' => 'integer',
        'price' => 'decimal:2',
        'requires_confirmation' => 'boolean',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function medicalRecords()
    {
        return $this->hasMany(MedicalRecord::class);
    }

    public function prescriptions()
    {
        return $this->hasMany(Prescription::class);
    }

    public function vaccinations()
    {
        return $this->hasMany(Vaccination::class);
    }

    /**
     * Serviços contratados neste agendamento (contrato
     * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.2) — substitui
     * `service_id` (FK única, deprecada) quando o agendamento tem mais de um item.
     */
    public function services(): HasMany
    {
        return $this->hasMany(AppointmentService::class);
    }

    /**
     * Linhas de cobrança lançadas durante o atendimento (contrato §13.3) — a "conta"
     * em aberto enquanto o atendimento está `in_progress` e a fatura não foi paga.
     */
    public function charges(): HasMany
    {
        return $this->hasMany(AppointmentCharge::class);
    }

    /** Fatura gerada automaticamente ao iniciar o atendimento (contrato §13.5). */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * `whereDate()` casts the column, which blocks index usage on this
     * `datetime` column — a plain range is sargable and semantically identical.
     */
    public function scopeToday($query)
    {
        $today = today();

        return $query->where('appointment_date', '>=', $today)
            ->where('appointment_date', '<', $today->copy()->addDay());
    }

    public function scopeUpcoming($query)
    {
        return $query->where('appointment_date', '>=', today())
            ->whereIn('status', ['scheduled', 'confirmed', 'pending']);
    }

    public function scopeForProfessional($query, $professionalId)
    {
        return $query->where('professional_id', $professionalId);
    }
}
