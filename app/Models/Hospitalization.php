<?php

namespace App\Models;

use App\Enums\HospitalizationStatus;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md e
 * docs/atendimento-veterinario/12-modulo-clinico-internacao.md.
 *
 * `total_cost` NÃO está mais em `$fillable` de propósito (doc 11 §4): o valor cobrado vem
 * sempre de `Invoice.total` (via `appointment`), nunca de um campo digitado à mão nesta
 * tabela. `daily_notes` segue o mesmo tratamento desde o módulo clínico (doc 12 §2): campo
 * de texto livre sem autoria nem hora, aposentado a favor de `HospitalizationProgressNote`.
 * As duas colunas continuam existindo no banco só por compatibilidade de leitura de código
 * antigo — nenhuma das duas é mais gravável por nenhum caminho de escrita.
 */
class Hospitalization extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'pet_id',
        'professional_id',
        'appointment_id',
        'indicating_medical_record_id',
        'admission_date',
        'discharge_date',
        'estimated_discharge_date',
        'reason',
        'status',
        'discharge_summary',
        'medications',
    ];

    protected $casts = [
        'admission_date' => 'date',
        'discharge_date' => 'date',
        'estimated_discharge_date' => 'date',
        'medications' => 'array',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    /** Contêiner de cobrança da estadia — contrato doc 11 §1. */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * O prontuário (de OUTRO atendimento, anterior) que indicou internar — contrato doc 12
     * §3.1. `null` quando a internação nasce de um walk-in de emergência sem consulta prévia
     * registrada na plataforma.
     */
    public function indicatingMedicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'indicating_medical_record_id');
    }

    public function progressNotes(): HasMany
    {
        return $this->hasMany(HospitalizationProgressNote::class)->orderBy('recorded_at');
    }

    public function careLogs(): HasMany
    {
        return $this->hasMany(HospitalizationCareLog::class)->orderBy('performed_at');
    }

    public function isActive(): bool
    {
        return $this->status === HospitalizationStatus::ACTIVE->value;
    }

    /**
     * Aviso derivado em leitura (contrato doc 12 §1.2), nunca bloqueante: horas desde a
     * última evolução registrada, ou `null` se nunca houve nenhuma. O limiar de "atrasado"
     * (`config('hospitalization.progress_note_overdue_hours')`) é exposto pelo Resource, não
     * decidido aqui — este método só calcula o número.
     */
    public function hoursSinceLastProgressNote(): ?int
    {
        $lastRecordedAt = HospitalizationProgressNote::query()
            ->where('hospitalization_id', $this->id)
            ->max('recorded_at');

        if ($lastRecordedAt === null) {
            return null;
        }

        return (int) Carbon::parse($lastRecordedAt)->diffInHours(now());
    }

    /**
     * Retorno agendado da estadia, para o documento de alta (contrato doc 12 §5.2) —
     * reaproveita `MedicalRecord.follow_up_appointment_id` (doc 00 §6, diferencial nº 3),
     * já gravado por `MedicalRecordFinalizationService::attachFollowUp()` ao finalizar
     * QUALQUER ato clínico Grupo A pendurado no `appointment_id` desta internação. Nenhum
     * campo novo. Mais de um ato com retorno agendado na mesma estadia → o mais recente
     * prevalece (o documento de alta mostra o PRÓXIMO compromisso, não o histórico todo).
     */
    public function followUpAppointment(): ?Appointment
    {
        return MedicalRecord::query()
            ->where('appointment_id', $this->appointment_id)
            ->whereNotNull('follow_up_appointment_id')
            ->latest('created_at')
            ->first()
            ?->followUpAppointment;
    }

    /**
     * Aviso derivado em leitura (contrato §2.1), nunca bloqueante: quantos dias da estadia
     * (admissão até alta, ou até hoje se ainda ativa) não têm diária lançada. Compara
     * DATAS cobertas, não quantidade de linhas — corrige o defeito em que duas diárias no
     * mesmo dia (lançamento em duplicidade) ou uma linha fora da janela inflavam/esvaziavam
     * a contagem por coincidência aritmética em vez de refletir o dia real.
     */
    public function missingDailyChargesCount(): int
    {
        return $this->stayWindowDates()->diff($this->coveredDailyChargeDates())->count();
    }

    /**
     * Cada dia corrido da estadia, do dia de admissão até a alta (ou até hoje, se a
     * internação ainda está ativa) — inclusive nas duas pontas.
     *
     * @return Collection<int, string>
     */
    private function stayWindowDates(): Collection
    {
        $lastDay = $this->discharge_date ?? Carbon::today();

        return collect(CarbonPeriod::create($this->admission_date, $lastDay))
            ->map(fn (Carbon $date): string => $date->toDateString());
    }

    /**
     * Dias já cobertos por uma diária lançada. `reference_date` (contrato
     * docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §2.1) é
     * gravado APENAS pelo lançamento de diária — nenhum outro fluxo de cobrança do
     * atendimento o envia (exame durante internação, serviço avulso etc. ficam com ele
     * nulo) — então "tem `reference_date`" identifica a diária tanto quando ela é um item
     * de catálogo (`service_id` de categoria `hospitalization`) quanto quando é lançada
     * como item livre (descrição digitada à mão, sem `service_id`), que é o caso comum na
     * prática. Identificar pela descrição seria frágil (texto livre, sem garantia de
     * padronização); o campo dedicado é o sinal confiável.
     *
     * Duas linhas na mesma `reference_date` (lançamento em duplicidade) contam o dia uma
     * vez só — `unique()` evita tanto uma contagem negativa quanto mascarar um dia
     * realmente descoberto em outro ponto da estadia. Linha com `reference_date` nula
     * (legado, anterior a esta coluna) é ignorada aqui — ver `launchedDailyChargesCount()`
     * antes desta correção; ela não descreve nenhum dia específico. Linha datada fora da
     * janela da estadia (admissão–alta) permanece na coleção, mas `diff()` em
     * `missingDailyChargesCount()` só remove datas que também estejam dentro da janela —
     * uma diária fora da janela não conta a favor de nenhum dia dela.
     *
     * @return Collection<int, string>
     */
    private function coveredDailyChargeDates(): Collection
    {
        return AppointmentCharge::query()
            ->where('appointment_id', $this->appointment_id)
            ->whereNotNull('reference_date')
            ->pluck('reference_date')
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->unique();
    }
}
