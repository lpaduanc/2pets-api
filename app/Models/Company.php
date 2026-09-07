<?php

namespace App\Models;

use App\DataTransferObjects\Cnpj;
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
    ];

    /** Regra de projeto: documento sempre gravado limpo. Ver `DocumentNumber`. */
    protected function cnpj(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => Cnpj::normalizeForStorage($value),
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
