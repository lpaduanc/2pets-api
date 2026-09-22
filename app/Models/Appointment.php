<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
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
        'organization_id',
        'location_id',
        'client_id',
        'pet_id',
        'service_id',
        'appointment_date',
        'appointment_time',
        'duration',
        'type',
        // Item 14 — vínculo opcional ao cadastro configurável de "tipo de atendimento"
        // (nome/cor/duração por dono). Nunca confundir com `type` (`ServiceCategory`, decide
        // prontuário/exame/faturamento) — ver migration de criação da coluna.
        'appointment_type_id',
        'status',
        'reason',
        'notes',
        'price',
        'booking_source',
        'requires_confirmation',
        'confirmed_at',
        'cancelled_at',
        'cancellation_reason',
        // Fase 6 do fluxo de agendamento — sinal (pagamento parcial antecipado), cobrado só
        // na confirmação. `deposit_status` default `none`: o caminho sem sinal (padrão do
        // produto) nunca toca estas duas colunas.
        'deposit_amount',
        'deposit_status',
        // Item 21 do backlog gap-simplesvet — "fila do dia". Rótulo de fila é derivado do
        // par (status, checked_in_at), sem um `queue_status` paralelo.
        'checked_in_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'location_id' => 'integer',
        'appointment_type_id' => 'integer',
        'appointment_date' => 'datetime',
        'appointment_time' => 'datetime:H:i',
        'duration' => 'integer',
        'price' => 'decimal:2',
        'requires_confirmation' => 'boolean',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'deposit_amount' => 'decimal:2',
        'checked_in_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    /**
     * Rótulo de fila do dia (item 21 do backlog gap-simplesvet) — derivado do par
     * (`status`, `checked_in_at`), sem uma segunda máquina de estados paralela a
     * `AppointmentStatus`.
     */
    public function queueLabel(): string
    {
        return match (true) {
            $this->status === AppointmentStatus::IN_PROGRESS->value => 'in_service',
            $this->status === AppointmentStatus::COMPLETED->value => 'done',
            $this->status === AppointmentStatus::NO_SHOW->value => 'no_show',
            $this->checked_in_at !== null && $this->isWaitableStatus() => 'waiting',
            default => 'scheduled',
        };
    }

    private function isWaitableStatus(): bool
    {
        return in_array($this->status, [AppointmentStatus::CONFIRMED->value, AppointmentStatus::SCHEDULED->value], true);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function appointmentType(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class);
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

    /**
     * `Payment` de SINAL (Fase 6) — `payments.appointment_id`, distinto de
     * `payments.invoice_id` (acerto final/adiantamento de internação). Um agendamento tem
     * no máximo um sinal, mas a relação é `HasMany` porque uma tentativa que falhou no
     * gateway não é sobrescrita (ver `AppointmentDepositService`).
     */
    public function depositPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'appointment_id');
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
