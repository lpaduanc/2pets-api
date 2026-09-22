<?php

namespace App\Models;

use App\Contracts\HasDepositSettings;
use App\DataTransferObjects\Cnpj;
use App\Enums\OrganizationType;
use App\Enums\StateRegistrationType;
use App\Enums\TaxRegime;
use App\Models\Concerns\HasGeoPoint;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A empresa (CNPJ) — clínica, laboratório, petshop, hotel, banho e tosa ou adestramento.
 * Nunca loga: quem loga é um `User` vinculado via `OrganizationMember`. Vet volante não tem
 * `Organization` — continua sendo só pessoa física (`User` + `Professional`).
 */
class Organization extends Model implements HasDepositSettings
{
    use HasFactory, HasGeoPoint, LogsActivity, SoftDeletes;

    protected $fillable = [
        'organization_type',
        'business_name',
        'cnpj',
        'description',
        'opening_hours',
        'closing_hours',
        'working_days',
        'service_radius_km',
        'deposit_enabled',
        'deposit_percentage',
        'services_offered',
        'products_sold',
        'address',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'zip_code',
        'latitude',
        'longitude',
        'technical_responsible_professional_id',
        'technical_responsible_name',
        'technical_responsible_crmv',
        'technical_responsible_crmv_state',
        'technical_responsible_verified',
        // Fiscal (doc 05) — `certificate_ref`/`certificate_expires_at` de propósito FORA desta
        // lista: só o fluxo do cofre externo (security-specialist) escreve neles.
        'tax_regime',
        'municipal_registration',
        'state_registration',
        'state_registration_type',
        'cnae_code',
        'special_tax_regime',
        'iss_rate',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_type' => OrganizationType::class,
            'working_days' => 'array',
            'services_offered' => 'array',
            'products_sold' => 'array',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'technical_responsible_verified' => 'boolean',
            'deposit_enabled' => 'boolean',
            'deposit_percentage' => 'decimal:2',
            'tax_regime' => TaxRegime::class,
            'state_registration_type' => StateRegistrationType::class,
            'iss_rate' => 'decimal:2',
            'certificate_expires_at' => 'datetime',
        ];
    }

    public function depositEnabled(): bool
    {
        return (bool) $this->deposit_enabled;
    }

    public function depositPercentage(): ?float
    {
        return $this->deposit_percentage === null ? null : (float) $this->deposit_percentage;
    }

    /**
     * Regra de projeto: documento sempre gravado limpo (só dígitos). Ver `DocumentNumber`.
     */
    protected function cnpj(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => Cnpj::normalizeForStorage($value),
        );
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function technicalResponsibleProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'technical_responsible_professional_id');
    }

    /**
     * Inverso de `organization_id` no grupo COMERCIAL (item 3 do split Pessoa/Organização).
     * Grupo CLÍNICO não entra aqui de propósito — ver a migration que criou a coluna.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @deprecated Estoque migrou para `Product` (`controls_stock=true`) — ver `products()` e
     *      docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md. Mantido só para
     *      quem ainda precisa consultar o legado histórico.
     */
    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(Availability::class);
    }

    public function blockedTimes(): HasMany
    {
        return $this->hasMany(BlockedTime::class);
    }

    /** Áreas de atendimento (item 21 do backlog gap-simplesvet). */
    public function serviceAreas(): HasMany
    {
        return $this->hasMany(ServiceArea::class);
    }

    public function waitlists(): HasMany
    {
        return $this->hasMany(Waitlist::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function reviewResponses(): HasMany
    {
        return $this->hasMany(ReviewResponse::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function adCampaigns(): HasMany
    {
        return $this->hasMany(AdCampaign::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['organization_type', 'business_name', 'cnpj', 'technical_responsible_verified'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
