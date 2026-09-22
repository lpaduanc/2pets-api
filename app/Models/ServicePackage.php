<?php

namespace App\Models;

use App\Contracts\Sellable;
use App\Enums\PackageValidityType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catálogo de pacote de serviços vendido — contrato
 * docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md.
 *
 * Terceiro `Sellable` do módulo comercial (produto e serviço já implementam): entra em
 * `sale_items` pelo mesmo `sellable_type`/`sellable_id` polimórfico, sem fluxo de venda
 * paralelo. `allowsPriceOverride()` fixo em `false` — ver o comentário do método.
 */
class ServicePackage extends Model implements Sellable
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'name',
        'description',
        'price',
        'validity_type',
        'validity_days',
        'fixed_expires_at',
        'allow_transfer_between_pets',
        'commission_percent',
        'show_in_price_list',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'validity_type' => PackageValidityType::class,
            'price' => 'decimal:2',
            'validity_days' => 'integer',
            'fixed_expires_at' => 'date',
            'allow_transfer_between_pets' => 'boolean',
            'commission_percent' => 'decimal:4',
            'show_in_price_list' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServicePackageItem::class);
    }

    public function soldPackages(): HasMany
    {
        return $this->hasMany(SoldPackage::class);
    }

    public function saleItems(): MorphMany
    {
        return $this->morphMany(SaleItem::class, 'sellable');
    }

    /**
     * Data de vencimento de UMA instância vendida hoje, a partir da regra de validade do
     * catálogo (regra de negócio 3). `null` quando `unlimited`.
     */
    public function expiresAtFrom(\DateTimeInterface $soldAt): ?\Carbon\CarbonImmutable
    {
        return match ($this->validity_type) {
            PackageValidityType::DAYS_FROM_SALE => \Carbon\CarbonImmutable::instance($soldAt)->addDays((int) $this->validity_days),
            PackageValidityType::FIXED_DATE => \Carbon\CarbonImmutable::parse($this->fixed_expires_at),
            PackageValidityType::UNLIMITED => null,
        };
    }

    // ----------------------------------------------------------------------
    // Sellable
    // ----------------------------------------------------------------------

    public function sellableName(): string
    {
        return $this->name;
    }

    public function sellableUnitPrice(): float
    {
        return (float) $this->price;
    }

    /**
     * Pacote não negocia preço no balcão: é uma composição já promocional por natureza, e
     * permitir desconto ad-hoc sobre um preço que já é desconto composto tende a gerar
     * prejuízo não intencional. Quem quiser negociar usa o desconto da VENDA
     * (`sales.discount_type/discount_value`), que incide sobre o total, não sobre o item.
     */
    public function allowsPriceOverride(): bool
    {
        return false;
    }

    public function commissionPercent(): ?float
    {
        return $this->commission_percent === null ? null : (float) $this->commission_percent;
    }

    /** Pacote nunca baixa estoque: o que ele carrega é crédito de sessão, não mercadoria. */
    public function movesStock(): bool
    {
        return false;
    }

    /** Sem custo de mercadoria, mesmo raciocínio de `Service::unitCost()`. */
    public function unitCost(): float
    {
        return 0.0;
    }

    public function appearsInPriceList(): bool
    {
        return (bool) $this->show_in_price_list;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
