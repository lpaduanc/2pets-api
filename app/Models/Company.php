<?php

namespace App\Models;

use App\DataTransferObjects\Cnpj;
use App\DataTransferObjects\Cpf;
use App\Enums\BudgetRange;
use App\Enums\EstimatedPetOwnersRange;
use App\Enums\IndustrySector;
use App\Enums\PreferredCommunicationChannel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_name',
        'cnpj',
        'contact_name',
        'contact_position',
        'phone',
        'website',
        'employee_count',
        'benefit_type',
        'notes',
        'legal_representative_name',
        'legal_representative_cpf',
        'legal_representative_birth_date',
        'legal_representative_phone',
        'industry_sector',
        'has_pet_policy',
        'estimated_pet_owners',
        'preferred_communication',
        'budget_range',
        'start_date_preference',
        'interested_services',
    ];

    protected $casts = [
        'legal_representative_birth_date' => 'date',
        'has_pet_policy' => 'boolean',
        'industry_sector' => IndustrySector::class,
        'estimated_pet_owners' => EstimatedPetOwnersRange::class,
        'preferred_communication' => PreferredCommunicationChannel::class,
        'budget_range' => BudgetRange::class,
        'start_date_preference' => 'date',
        'interested_services' => 'array',
    ];

    /** Regra de projeto: documento sempre gravado limpo. Ver `DocumentNumber`. */
    protected function cnpj(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => Cnpj::normalizeForStorage($value),
        );
    }

    /**
     * Mesma regra de `cnpj`, aplicada ao CPF do representante legal — invariante de escrita
     * independente do Form Request já normalizar em `prepareForValidation()`.
     */
    protected function legalRepresentativeCpf(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => Cpf::normalizeForStorage($value),
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
