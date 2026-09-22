<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fornecedor — contrato docs/gap-simplesvet/06-compras-fornecedores-xml.md. Guarda o contato do
 * REPRESENTANTE separado do contato da empresa (ver a migration).
 */
class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'legal_name',
        'trade_name',
        'document',
        'state_registration',
        'phone',
        'email',
        'sales_rep_name',
        'sales_rep_phone',
        'sales_rep_email',
        'address_zip',
        'address_street',
        'address_number',
        'address_complement',
        'address_district',
        'address_city',
        'address_state',
        'payment_terms',
        'lead_time_days',
        'notes',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'lead_time_days' => 'integer',
        ];
    }

    /** CNPJ/CPF gravado só com dígitos — é a chave de casamento do XML. */
    public function setDocumentAttribute(?string $value): void
    {
        $digits = $value === null ? null : preg_replace('/\D/', '', $value);
        $this->attributes['document'] = $digits === '' ? null : $digits;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function productCodes(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function displayName(): string
    {
        return $this->trade_name ?: $this->legal_name;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
