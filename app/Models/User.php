<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'user_type',
        'google_id',
        'phone',
        'address',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'zip_code',
        'latitude',
        'longitude',
        'cpf',
        'cnpj',
        'gender',
        'occupation',
        'employee_count',
        'additional_notes',
        'birth_date',
        'email_verified',
        'email_verification_token',
        'email_verification_sent_at',
        'profile_completed',
        'registration_status',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
        'is_suspended',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_sent_at' => 'datetime',
            'birth_date' => 'date',
            'password' => 'hashed',
            'email_verified' => 'boolean',
            'profile_completed' => 'boolean',
            'is_suspended' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function pets()
    {
        return $this->hasMany(Pet::class);
    }

    public function professional()
    {
        return $this->hasOne(Professional::class);
    }

    public function company()
    {
        return $this->hasOne(Company::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function professionalAsTechnicalResponsible()
    {
        return $this->hasMany(Professional::class, 'technical_responsible_id');
    }

    public function appointmentsAsClient()
    {
        return $this->hasMany(Appointment::class, 'client_id');
    }

    public function invoicesAsClient()
    {
        return $this->hasMany(Invoice::class, 'client_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
