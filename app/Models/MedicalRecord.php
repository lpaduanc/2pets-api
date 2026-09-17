<?php

namespace App\Models;

use App\Enums\MedicalRecordStatus;
use App\Enums\ServiceCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicalRecord extends Model
{
    use SoftDeletes;

    /**
     * Relações exigidas por `MedicalRecordResource`. `Model::preventLazyLoading()` está ativo
     * fora de produção — listar aqui evita que um novo call site esqueça um eager load e só
     * descubra em produção (mesmo padrão de `Prescription::RESOURCE_RELATIONS`).
     *
     * `linkedPrescriptions.*` reaproveita `Prescription::RESOURCE_RELATIONS` porque
     * `MedicalRecordResource` embrulha cada uma em `PrescriptionResource`, que exige esses
     * eager loads (mesma regra de `Model::preventLazyLoading()`).
     *
     * @var list<string>
     */
    public const RESOURCE_RELATIONS = [
        'pet', 'professional', 'finalizer', 'addenda.author', 'attachments',
        'linkedPrescriptions.pet.user', 'linkedPrescriptions.professional',
        'linkedPrescriptions.items', 'linkedPrescriptions.canceledBy',
        'reportedPetDataAppliedBy', 'invoice:id,appointment_id',
    ];

    protected $fillable = [
        'pet_id',
        'professional_id',
        'appointment_id',
        'act_category',
        'record_date',
        'status',
        'finalized_at',
        'finalized_by',
        'chief_complaint',
        'chief_complaint_notes',
        'weight',
        'temperature',
        'heart_rate',
        'respiratory_rate',
        'physical_exam',
        'capillary_refill_time',
        'hydration_status',
        'body_condition_score',
        'pain_score',
        'subjective',
        'objective',
        'assessment',
        'plan',
        'symptoms',
        'diagnosis',
        'diagnosis_status',
        'treatment_plan',
        'treatment_actions',
        'notes',
        'summary_for_tutor',
        'previous_record_id',
        'follow_up_appointment_id',
        'anamnesis_signs',
        'behavior_findings',
        'recent_routine_change',
        'recent_routine_change_notes',
        'context_flags',
        'reported_pet_data',
    ];

    /**
     * `reported_pet_data_applied_at`/`_by` ficam FORA de `$fillable` de propósito: só
     * `PetDataPromotionService::apply()` grava essas duas colunas, nunca o `PUT` de rascunho
     * do vet — promover o relato ao cadastro é ação exclusiva do tutor (contrato
     * docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §D).
     */

    /**
     * `prescriptions` (JSON legado) fica FORA de `$fillable` de propósito — contrato
     * docs/atendimento-veterinario/03-contrato-receituario.md §2: "para de ser gravável";
     * `Prescription` (tabela própria) é a verdade a partir desta fatia. A coluna continua no
     * `$casts` abaixo só para que prontuário antigo finalizado continue legível (nunca perde
     * o texto que já foi escrito ali).
     */
    protected $casts = [
        'record_date' => 'date',
        'finalized_at' => 'datetime',
        'weight' => 'decimal:2',
        'temperature' => 'decimal:1',
        'heart_rate' => 'integer',
        'respiratory_rate' => 'integer',
        'physical_exam' => 'array',
        'body_condition_score' => 'integer',
        'pain_score' => 'integer',
        'symptoms' => 'array',
        'prescriptions' => 'array',
        'status' => MedicalRecordStatus::class,
        'act_category' => ServiceCategory::class,
        'anamnesis_signs' => 'array',
        'behavior_findings' => 'array',
        'context_flags' => 'array',
        'treatment_actions' => 'array',
        'recent_routine_change' => 'boolean',
        'reported_pet_data' => 'array',
        'reported_pet_data_applied_at' => 'datetime',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * `true` quando este prontuário foi aberto por `HospitalizationClinicalActService`
     * (ato clínico Grupo A, ex.: cirurgia, ocorrido DURANTE uma internação já em curso) —
     * contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §2.2.
     * `false` para o fluxo normal (`ConsultationService::start()`), onde `appointment.type`
     * já identifica o ato sozinho, porque o agendamento tem no máximo um prontuário.
     */
    public function isHospitalizationAct(): bool
    {
        return $this->act_category !== null;
    }

    /**
     * Fatura do atendimento (contrato docs/atendimento-veterinario/09-faturamento-do-
     * atendimento.md §13.5) — ligada por `appointment_id` direto (mesma chave desta linha),
     * não por `Invoice::medical_record_id` (coluna legada, sem escrita desde a §13). Usada só
     * por `MedicalRecordResource::invoice_id`, sempre com `->with('invoice:id,appointment_id')`
     * para não carregar a fatura inteira.
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'appointment_id', 'appointment_id');
    }

    /** Quem assinou a finalização — pode ser diferente do autor original em teoria, hoje é sempre o mesmo. */
    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /** Sempre o tutor — só ele pode promover `reported_pet_data` ao cadastro (contrato §D). */
    public function reportedPetDataAppliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_pet_data_applied_by');
    }

    public function previousRecord(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_record_id');
    }

    public function followUpAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'follow_up_appointment_id');
    }

    /** Append-only — ver `MedicalRecordAddendum`. Ordenado do mais antigo para o mais novo (linha do tempo de leitura). */
    public function addenda(): HasMany
    {
        return $this->hasMany(MedicalRecordAddendum::class)->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MedicalRecordAttachment::class);
    }

    /**
     * `Prescription` estruturada vinculada a este atendimento (contrato
     * docs/atendimento-veterinario/03-contrato-receituario.md §2) — NÃO chama-se
     * `prescriptions()` de propósito: `prescriptions` já é o nome do atributo do JSON legado
     * (`$casts` acima), e no Eloquent um atributo tem precedência sobre um método de relação
     * de mesmo nome no `__get()` mágico — `$record->prescriptions` continuaria devolvendo a
     * coluna antiga mesmo com uma relação chamada assim. `MedicalRecordResource` expõe as
     * duas sob chaves diferentes (`prescriptions` = esta relação, `legacy_prescriptions` = a
     * coluna).
     */
    public function linkedPrescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class)->orderBy('created_at');
    }

    public function isDraft(): bool
    {
        return $this->status === MedicalRecordStatus::DRAFT;
    }

    public function isFinalized(): bool
    {
        return $this->status === MedicalRecordStatus::FINALIZED;
    }
}
