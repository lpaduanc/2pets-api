<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'species',
        'breed',
        'birth_date',
        'gender',
        'weight',
        'color',
        'neutered',
        'blood_type',
        'allergies',
        'chronic_diseases',
        'current_medications',
        'temperament',
        'behavior_notes',
        'social_with',
        'notes',
        'image_url',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'weight' => 'decimal:2',
        'neutered' => 'boolean',
        'temperament' => 'array',
        'social_with' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
