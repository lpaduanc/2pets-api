<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Vínculo pessoa (`User`) × organização (`Organization`), com papel e permissões próprias.
 * Renomeado de `Staff` (Fase 1 do split Pessoa/Organização) — nenhuma rota usava a tabela
 * antiga, então o rename não precisou de camada de compatibilidade.
 *
 * O papel aqui é operacional dentro da organização, não o papel Spatie: `role=owner` não
 * concede `isVeterinarian()` nem qualquer outra permissão de plataforma — quem decide
 * autorização de ato clínico continua sendo o papel Spatie do `User` (ver `User::VET_ROLES`).
 * `role` agora é a fonte de verdade de permissão (Fase 2, `OrganizationRole`) — `permissions`
 * (JSON) só serve pra exceção pontual e nunca sobrepõe a blindagem clínica (`hasPermission()`).
 */
class OrganizationMember extends Model
{
    use HasFactory, LogsActivity;

    /**
     * Os 8 atos privativos de veterinário (mesma lista de `ClinicOwnerClinicalPermissionsTest`)
     * — nenhum cargo não-clínico pode ganhá-los, nem por exceção pontual em `permissions`.
     *
     * @var list<string>
     */
    private const CLINICAL_PERMISSIONS = [
        'medical-records.create',
        'prescriptions.create',
        'vaccinations.create',
        'exams.create',
        'hospitalizations.create',
        'hospitalizations.discharge',
        'surgeries.create',
        'surgeries.cancel',
    ];

    public const ROLE_OWNER = 'owner';

    public const ROLE_VETERINARIAN = 'veterinarian';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_RECEPTIONIST = 'receptionist';

    public const ROLE_GROOMER = 'groomer';

    public const ROLE_TECHNICIAN = 'technician';

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
        'employee_id',
        'hire_date',
        'termination_date',
        'employment_type',
        'hourly_rate',
        'monthly_salary',
        'permissions',
        'is_active',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'hire_date' => 'date',
            'termination_date' => 'date',
            'hourly_rate' => 'decimal:2',
            'monthly_salary' => 'decimal:2',
            'permissions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(StaffSchedule::class);
    }

    public function timeOff(): HasMany
    {
        return $this->hasMany(StaffTimeOff::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'assigned_staff_id');
    }

    /**
     * `permissions` (JSON) é exceção pontual dentro do que o cargo já permite — nunca pode
     * conceder ato clínico a quem não é veterinário, mesmo que o JSON tente (regra
     * regulatória não-negociável, ver `OrganizationRole::isClinical()`).
     */
    public function hasPermission(string $permission): bool
    {
        if (in_array($permission, self::CLINICAL_PERMISSIONS, true) && ! $this->role->isClinical()) {
            return false;
        }

        return in_array($permission, $this->permissions ?? [], true);
    }

    public function isAvailableOn(\DateTimeInterface $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return ! $this->isOnApprovedTimeOff($date);
    }

    private function isOnApprovedTimeOff(\DateTimeInterface $date): bool
    {
        return $this->timeOff()
            ->where('status', 'approved')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['organization_id', 'user_id', 'role', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
