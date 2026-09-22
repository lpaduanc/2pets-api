<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        // `StaffTimeOff.staff_id` é o nome real da coluna (herdado do rename Staff →
        // OrganizationMember) — sem a FK explícita, o Eloquent adivinha
        // `organization_member_id`, que não existe. Bug dormente até este relacionamento
        // ganhar o primeiro caller de verdade (`StaffTimeOffChecker`, item 21).
        return $this->hasMany(StaffTimeOff::class, 'staff_id');
    }

    /**
     * Áreas de atendimento que este membro executa (item 21 do backlog gap-simplesvet).
     * Vazio = sem restrição, continua elegível para qualquer serviço (regra 2 da spec).
     */
    public function serviceAreas(): BelongsToMany
    {
        return $this->belongsToMany(ServiceArea::class, 'organization_member_service_areas');
    }

    /**
     * Elegível para um serviço de área `$serviceAreaId`? Sem nenhuma área cadastrada, o
     * membro atende qualquer serviço (comportamento não regressivo); com pelo menos uma,
     * só atende se a área do serviço estiver entre as suas (ou o serviço não tiver área).
     */
    public function canServeArea(?int $serviceAreaId): bool
    {
        if ($serviceAreaId === null) {
            return true;
        }

        $myAreaIds = $this->serviceAreas->pluck('id');

        return $myAreaIds->isEmpty() || $myAreaIds->contains($serviceAreaId);
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
        if (self::isClinicalPermission($permission) && ! $this->role->isClinical()) {
            return false;
        }

        return in_array($permission, $this->permissions ?? [], true);
    }

    /**
     * Expõe a lista privada de atos clínicos para quem precisa VALIDAR uma tentativa de
     * concessão antes de gravar (`UpdateOrganizationMemberPermissionsRequest`, item 22) — sem
     * duplicar a lista em um segundo lugar, que divergiria de `hasPermission()` mais cedo ou
     * mais tarde.
     */
    public static function isClinicalPermission(string $permission): bool
    {
        return in_array($permission, self::CLINICAL_PERMISSIONS, true);
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
            // `permissions` entrou na lista no item 22 — override pontual concedido pelo
            // dono precisa de trilha de auditoria (quem, quando, de → para).
            ->logOnly(['organization_id', 'user_id', 'role', 'is_active', 'permissions'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
