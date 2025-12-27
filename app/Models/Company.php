<?php

namespace App\Models;

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
