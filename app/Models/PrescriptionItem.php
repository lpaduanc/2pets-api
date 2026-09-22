<?php

namespace App\Models;

use App\Enums\PrescriptionFrequency;
use App\Enums\PrescriptionPharmaceuticalForm;
use App\Enums\PrescriptionRoute;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Um medicamento dentro de uma `Prescription` — contrato
 * docs/atendimento-veterinario/03-contrato-receituario.md §2. Substitui o antigo array
 * `prescriptions.medications` (JSON).
 *
 * Sem soft delete de propósito: ver comentário da migration `create_prescription_items_table`.
 */
class PrescriptionItem extends Model
{
    protected $fillable = [
        'position',
        'product_id',
        'active_ingredient',
        'commercial_name',
        'concentration',
        'pharmaceutical_form',
        'form_notes',
        'route',
        'route_notes',
        'dose_value',
        'dose_unit',
        'dose_per_kg',
        'dose_calculated',
        'frequency',
        'frequency_custom_hours',
        'frequency_notes',
        'duration_text',
        'is_continuous_use',
        'quantity_to_dispense',
        'instructions_for_tutor',
        'is_controlled',
        'starts_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'pharmaceutical_form' => PrescriptionPharmaceuticalForm::class,
        'route' => PrescriptionRoute::class,
        'dose_value' => 'decimal:3',
        'dose_unit' => 'string',
        'dose_per_kg' => 'decimal:3',
        'dose_calculated' => 'boolean',
        'frequency' => PrescriptionFrequency::class,
        'frequency_custom_hours' => 'integer',
        'is_continuous_use' => 'boolean',
        'is_controlled' => 'boolean',
        'starts_at' => 'datetime',
    ];

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Administrações registradas durante internação — contrato docs/gap-simplesvet/specs/
     * 12-internacao-mapa-execucao-spec.md. Só populado quando este item pendura numa
     * prescrição de internação (`hospitalization_care_logs.prescription_item_id`). */
    public function careLogs(): HasMany
    {
        return $this->hasMany(HospitalizationCareLog::class);
    }

    /**
     * Próxima dose esperada, SEMPRE calculada em leitura (regra de negócio 4 da spec 12) —
     * nenhuma linha de execução é persistida. Ancorada na última administração registrada
     * quando existir; senão, em `starts_at` (quando a prescrição pendura numa internação).
     * Frequência sem intervalo previsível (dose única/SOS/livre) não tem próxima dose depois
     * da primeira administração — `null`.
     */
    public function nextDueAt(?CarbonInterface $lastGivenAt): ?CarbonInterface
    {
        if ($lastGivenAt === null) {
            return $this->starts_at !== null ? Carbon::instance($this->starts_at) : null;
        }

        $intervalHours = $this->frequency?->intervalHours($this->frequency_custom_hours);

        if ($intervalHours === null) {
            return null;
        }

        return Carbon::instance($lastGivenAt)->addHours($intervalHours);
    }

    public function isMedicationLate(?CarbonInterface $nextDueAt): bool
    {
        return $nextDueAt !== null && $nextDueAt->isPast();
    }

    /** Nome exibido em PDF, lembrete e promoção a `PetMedication` — nunca vazio na tela. */
    public function displayName(): string
    {
        return $this->commercial_name ?? $this->active_ingredient ?? 'Medicamento sem nome';
    }

    /**
     * Rótulo de via pronto para texto server-renderizado (PDF, lembrete). `other` sempre
     * acompanha texto livre (contrato §4) — cai no rótulo genérico quando a nota está vazia.
     */
    public function routeLabel(): ?string
    {
        if ($this->route === PrescriptionRoute::OTHER) {
            return $this->route_notes ?? PrescriptionRoute::OTHER->label();
        }

        return $this->route?->label();
    }

    public function pharmaceuticalFormLabel(): ?string
    {
        if ($this->pharmaceutical_form === PrescriptionPharmaceuticalForm::OTHER) {
            return $this->form_notes ?? PrescriptionPharmaceuticalForm::OTHER->label();
        }

        return $this->pharmaceutical_form?->label();
    }

    public function frequencyLabel(): ?string
    {
        if ($this->frequency === PrescriptionFrequency::EVERY_X_HOURS && $this->frequency_custom_hours !== null) {
            return "A cada {$this->frequency_custom_hours}h";
        }

        if ($this->frequency === PrescriptionFrequency::OTHER) {
            return $this->frequency_notes ?? PrescriptionFrequency::OTHER->label();
        }

        return $this->frequency?->label();
    }
}
