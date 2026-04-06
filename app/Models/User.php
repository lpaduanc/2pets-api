<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasMedia
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory,
        Notifiable,
        HasApiTokens,
        HasRoles,
        InteractsWithMedia,
        LogsActivity;

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
        'stripe_customer_id',
        'stripe_subscription_id',
        // LGPD fields
        'terms_accepted_at',
        'privacy_accepted_at',
        'marketing_consent',
        'data_sharing_consent',
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
            // LGPD casts
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'marketing_consent' => 'boolean',
            'data_sharing_consent' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------
    // Activity Log (spatie/laravel-activitylog)
    // ------------------------------------------------------------------

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name', 'email', 'phone', 'role', 'user_type',
                'registration_status', 'is_suspended', 'profile_completed',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ------------------------------------------------------------------
    // Media Library collections
    // ------------------------------------------------------------------

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);

        $this->addMediaCollection('documents');
    }

    // ------------------------------------------------------------------
    // Relationships
    // ------------------------------------------------------------------

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

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
}
