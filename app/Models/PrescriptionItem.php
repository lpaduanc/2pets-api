<?php

namespace App\Models;

use App\Enums\PrescriptionFrequency;
use App\Enums\PrescriptionPharmaceuticalForm;
use App\Enums\PrescriptionRoute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
