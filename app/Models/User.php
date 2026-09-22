<?php

namespace App\Models;

use App\DataTransferObjects\Cnpj;
use App\DataTransferObjects\Cpf;
use App\Enums\AccountStatus;
use App\Enums\DeactivationReason;
use App\Models\Concerns\HasGeoPoint;
use App\Notifications\Contracts\BypassesDeactivationGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasMedia
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens,
        HasFactory,
        HasGeoPoint,
        HasRoles,
        // Mantem `location` (geography) sincronizada com latitude/longitude a cada save().
        InteractsWithMedia,
        LogsActivity,
        // O `notify()` sobrescrito abaixo é o portão de desativação de conta; ele precisa
        // chamar a implementação original, que vem DESTE trait e não da classe pai
        // (`Illuminate\Foundation\Auth\User` não usa `Notifiable`). Sem o alias,
        // `parent::notify()` cai no `__call` do Eloquent e toda notificação a usuário
        // estoura com "Call to undefined method App\Models\User::notify()".
        Notifiable {
            notify as private baseNotify;
        }

    // SoftDeletes coexiste com LGPD anonimização: delete() esconde o registro; anonymize() apaga dados sensíveis in-place.
    use SoftDeletes;

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
        'birthday_month',
        'birthday_day',
        'email_verified_at',
        'email_verification_token',
        'email_verification_sent_at',
        'profile_completed',
        'registration_status',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
        'is_suspended',
        // Desativação voluntária (não confundir com `is_suspended`, punitiva)
        'deactivated_at',
        'deactivation_reason',
        'deactivation_note',
        'deactivated_by',
        'reactivated_at',
        'stripe_customer_id',
        'stripe_subscription_id',
        // LGPD fields
        'terms_accepted_at',
        'privacy_accepted_at',
        'marketing_consent',
        'data_sharing_consent',
        // docs/gap-simplesvet/specs/17-crm-mensageria-spec.md: opt-in de campanha por canal,
        // separado do transacional.
        'consent_sms_marketing',
        'consent_whatsapp_marketing',
        // As 6 chaves abaixo já eram aceitas por `LgpdController::updateConsent()`
        // (`GRANULAR_KEYS`) mas faltavam aqui — com `preventSilentlyDiscardingAttributes()`
        // fora de produção, `$user->update([...])` lançava `MassAssignmentException` para
        // qualquer uma delas. Achado reportado em docs/gap-simplesvet/contratos/17-contrato-api.md,
        // corrigido em docs/gap-simplesvet/contratos/20-contrato-api.md.
        'consent_search_visibility',
        'consent_share_with_vets',
        'consent_push_notifications',
        'consent_sms_transactional',
        'consent_whatsapp_transactional',
        'consent_analytics',
        // Fiscal (doc 05) — dados do cliente PESSOA JURÍDICA para nota fiscal, preenchidos
        // pelo próprio tutor/cliente no perfil dele.
        'tax_regime',
        'municipal_registration',
        'state_registration',
        'state_registration_type',
        'foreign_document',
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
            'birthday_month' => 'integer',
            'birthday_day' => 'integer',
            'password' => 'hashed',
            'profile_completed' => 'boolean',
            'is_suspended' => 'boolean',
            'reviewed_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'deactivation_reason' => DeactivationReason::class,
            'reactivated_at' => 'datetime',
            // LGPD casts
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'marketing_consent' => 'boolean',
            'data_sharing_consent' => 'boolean',
            'consent_sms_marketing' => 'boolean',
            'consent_whatsapp_marketing' => 'boolean',
            'consent_search_visibility' => 'boolean',
            'consent_share_with_vets' => 'boolean',
            'consent_push_notifications' => 'boolean',
            'consent_sms_transactional' => 'boolean',
            'consent_whatsapp_transactional' => 'boolean',
            'consent_analytics' => 'boolean',
            'tax_regime' => \App\Enums\TaxRegime::class,
            'state_registration_type' => \App\Enums\StateRegistrationType::class,
        ];
    }

    // ------------------------------------------------------------------
    // Attribute mutators
    // ------------------------------------------------------------------

    /**
     * Regra de projeto: documento é sempre gravado limpo (só dígitos). Qualquer caminho pode
     * enviar `123.456.789-00` ou `12345678900` — o mutator normaliza. Quem busca precisa
     * normalizar a entrada também (ver `DocumentNumber`).
     */
    protected function cpf(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => Cpf::normalizeForStorage($value),
        );
    }

    protected function cnpj(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => Cnpj::normalizeForStorage($value),
        );
    }

    /**
     * Deriva de `email_verified_at`, nunca de uma coluna própria. Antes desta mudança, o
     * schema tinha DUAS fontes de verdade para o mesmo fato (`email_verified` boolean +
     * `email_verified_at` timestamp) que podiam divergir — o login OAuth do Google
     * (`AuthController::handleGoogleCallback()`) só setava `email_verified_at`, então um
     * usuário que entrava com o Google ficava para sempre bloqueado no `if (! $user->email_verified)`
     * do login por e-mail/senha. `email_verified_at` é o padrão do próprio Laravel
     * (`MustVerifyEmail`) e a única gravação que sempre acontece em qualquer fluxo de
     * verificação (token por e-mail ou OAuth) — a coluna `email_verified` continua existindo
     * no schema por ora (evita quebrar leitura direta via `DB::table('users')`/relatório), mas
     * nenhum código de aplicação grava nela: este accessor sempre ganha de qualquer valor
     * salvo ali.
     */
    protected function emailVerified(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->email_verified_at !== null,
        );
    }

    // ------------------------------------------------------------------
    // ------------------------------------------------------------------
    // Visibilidade pública de profissional
    // ------------------------------------------------------------------

    /**
     * Único lugar que define "este profissional aparece para o público".
     *
     * Estes quatro filtros são o predicado dos índices parciais
     * `idx_users_visible_professional_location` (GIST) e `idx_users_visible_professional_name`
     * (BTREE), criados em `2026_09_06_000001_add_search_performance_indexes_to_users_table`.
     * O Postgres só usa um índice parcial quando consegue provar que o WHERE da query implica
     * o predicado do índice — e quando não consegue, ele **não avisa**: a busca simplesmente
     * volta a varrer ~200 mil linhas via `idx_users_location` em vez de ~35 mil aqui.
     *
     * Antes deste scope a mesma condição estava copiada em três lugares
     * (`ProfessionalSearchService::buildBaseQuery()`, `Public\ProfessionalController::show()`,
     * `Public\SearchController::featured()`) mais o predicado do índice — quatro cópias que
     * precisavam mudar juntas, sem nada que acusasse quando não mudassem.
     *
     * ⚠️ Alterar qualquer filtro daqui EXIGE migration nova recriando os dois índices com o
     * mesmo predicado. `ProfessionalVisibilityIndexTest` falha se os dois saírem de sincronia.
     */
    public function scopeVisibleProfessional(Builder $query): Builder
    {
        return $query
            ->where('role', 'professional')
            ->where('profile_completed', true)
            ->where('registration_status', 'approved')
            ->where('is_suspended', false)
            ->whereNull('deactivated_at');
    }

    // Role helpers
    // ------------------------------------------------------------------

    /**
     * Spatie role names that identify a veterinarian. The `users.role` column is a coarse
     * bucket (`tutor|professional|admin`) and `users.user_type` is unreliable in legacy
     * rows — the real role always lives in Spatie.
     */
    public const VET_ROLES = ['veterinarian', 'vet_freelancer', 'clinic_vet'];

    public function isVeterinarian(): bool
    {
        return $this->hasAnyRole(self::VET_ROLES);
    }

    // ------------------------------------------------------------------
    // Desativação voluntária de conta (não confundir com `is_suspended`, punitiva, nem
    // com `deleted_at`, que só existe após anonimização LGPD)
    // ------------------------------------------------------------------

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /**
     * Conta criada por outra pessoa em nome deste tutor (fluxo de paciente novo,
     * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §2), que ele ainda não
     * reivindicou definindo a própria senha. `password IS NULL` é a ÚNICA fonte de verdade —
     * nunca inferir isso de `registration_status` sozinho, que também vale `pending` para outros
     * fluxos (ex.: empresa parceira aguardando aprovação).
     */
    public function isUnclaimed(): bool
    {
        return $this->password === null;
    }

    public function accountStatus(): AccountStatus
    {
        return AccountStatus::fromUser($this);
    }

    /**
     * Portão único para TODA notificação enviada via `notify()` — cobre tanto
     * `NotificationService` (que chama `$user->notify(new InAppNotification(...))` no fim de
     * `sendNotification()`) quanto os disparos diretos dos Console Commands de lembrete
     * (`SendHealthReminders`, `SendScheduledNotifications`), sem precisar repetir a checagem em
     * cada call site — e sem risco de um novo lembrete esquecer de checar.
     *
     * Comunicação operacional (lembrete de vacina, consulta, mensagem) para assim que a conta é
     * desativada. A campanha de reativação é a exceção deliberada: uma Notification que
     * implemente `BypassesDeactivationGate` continua sendo entregue — hoje nenhuma implementa.
     *
     * @param  Notification  $instance
     */
    public function notify($instance): void
    {
        if ($this->isDeactivated() && ! $instance instanceof BypassesDeactivationGate) {
            Log::info('Notification suppressed: recipient account is deactivated', [
                'user_id' => $this->id,
                'notification' => $instance::class,
            ]);

            return;
        }

        $this->baseNotify($instance);
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
                'deactivated_at', 'deactivation_reason',
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

    /**
     * Etiquetas atribuídas a este usuário COMO CLIENTE de um profissional/organização
     * (`docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`) — não confundir com papel
     * de plataforma. `withTimestamps()` casa com `taggables.created_at/updated_at`.
     */
    public function tags(): \Illuminate\Database\Eloquent\Relations\MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
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

    /**
     * Organizações (empresas) às quais esta pessoa está vinculada via `organization_members`,
     * em qualquer papel (`owner`, `veterinarian`, `assistant`...). Fase 1 do split
     * Pessoa/Organização — uma pessoa pode ter vínculo com N organizações.
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_members')
            ->withPivot(['role', 'is_active'])
            ->withTimestamps();
    }

    /**
     * Subconjunto de `organizations()` em que esta pessoa é `owner` — quem cadastrou o
     * negócio ou assumiu a titularidade dele.
     */
    public function ownedOrganizations(): BelongsToMany
    {
        return $this->organizations()->wherePivot('role', OrganizationMember::ROLE_OWNER);
    }

    /**
     * Vínculos ATIVOS desta pessoa com organizações — usado por `UserResource`/`GET /user`
     * pra expor `organizations` sem N+1 (eager load `activeOrganizationMemberships.organization`
     * em `AuthController::user()`). Tutor e vet volante nunca têm nenhum: a coleção vem vazia.
     */
    public function activeOrganizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class)->where('is_active', true);
    }

    /**
     * Um vínculo por ORGANIZAÇÃO, e não um por linha de `organization_members`.
     *
     * A mesma pessoa pode ter mais de um vínculo com a MESMA organização — o caso real é o
     * representante que também é responsável técnico: ele recebe `owner` (posse do negócio) e
     * `veterinarian` (habilitação clínica, que é o que concede `clinic_vet`). São duas linhas
     * porque são dois fatos distintos, e `UserRoleReconciler` depende das duas.
     *
     * Mas `GET /user` responde "de quais organizações eu faço parte" — ali a organização tem que
     * aparecer UMA vez, senão o seletor de organização do app mostra a mesma clínica duplicada.
     * Quando há mais de um vínculo, `owner` prevalece, por ser o que descreve a relação da
     * pessoa com o negócio.
     *
     * @return EloquentCollection<int, OrganizationMember>
     */
    public function primaryMembershipPerOrganization(): EloquentCollection
    {
        return $this->activeOrganizationMemberships
            ->sortBy(fn (OrganizationMember $member): int => $member->role === OrganizationMember::ROLE_OWNER ? 0 : 1)
            ->unique('organization_id')
            ->values();
    }

    /**
     * A organização "ativa" desta pessoa para preencher `organization_id` em registros
     * comerciais novos (`Invoice`, `Service`) — contrato
     * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §12.6. `null` para vet
     * volante (nenhum vínculo). Quando a pessoa tem mais de uma organização, prevalece a
     * mesma prioridade de `primaryMembershipPerOrganization()` (dono antes de vínculo comum).
     */
    public function activeOrganizationId(): ?int
    {
        return $this->primaryMembershipPerOrganization()->first()?->organization_id;
    }

    /**
     * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §5: "dono da
     * organização" concede a mesma autoridade do autor sobre fatura/conta daquela empresa.
     */
    public function ownsOrganization(int $organizationId): bool
    {
        return $this->ownedOrganizations()->where('organizations.id', $organizationId)->exists();
    }

    /**
     * Qualquer vínculo ativo (não só dono) — usado para "front desk cobra, não decide"
     * (contrato §5: qualquer membro ativo pode receber pagamento de fatura já emitida).
     */
    public function isActiveMemberOfOrganization(int $organizationId): bool
    {
        return $this->activeOrganizationMemberships()->where('organization_id', $organizationId)->exists();
    }

    /**
     * A exceção pontual concedida pelo dono (`organization_members.permissions`) em QUALQUER
     * organização em que esta pessoa tenha vínculo ativo hoje, respeitando a blindagem
     * clínica de `OrganizationMember::hasPermission()` — revisão de segurança, achado Médio 4:
     * sem este método, o override gravado por `PUT organizations/{org}/members/{member}/permissions`
     * nunca tinha efeito em `EnsurePermission` nem em `GET me/permissions`.
     *
     * Acessar `activeOrganizationMemberships` como propriedade (e não `()`) reaproveita o
     * cache de relação do Eloquent: a mesma instância de `$user` resolvida pelo Sanctum no
     * início do request paga a query uma vez só, mesmo com várias chamadas neste método.
     */
    public function hasOrganizationPermissionOverride(string $permission): bool
    {
        return $this->activeOrganizationMemberships
            ->contains(fn (OrganizationMember $member): bool => $member->hasPermission($permission));
    }

    /**
     * União das exceções pontuais já efetivamente concedidas (pós blindagem clínica) em
     * todas as organizações ativas desta pessoa — usado por `PermissionSummaryService` para
     * que `GET me/permissions` reflita a mesma fonte de verdade de `EnsurePermission`.
     *
     * @return list<string>
     */
    public function organizationPermissionOverrides(): array
    {
        return $this->activeOrganizationMemberships
            ->flatMap(fn (OrganizationMember $member): array => array_values(array_filter(
                $member->permissions ?? [],
                fn (string $permission): bool => $member->hasPermission($permission)
            )))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Contrato docs/atendimento-veterinario/12-modulo-clinico-internacao.md §6.2: terceiro
     * nível de autorização sobre uma internação — um colega de plantão da MESMA organização
     * que já tem autoridade para escrever prontuário em QUALQUER lugar do sistema
     * (`medical-records.create`, Spatie) ganha autoridade sobre uma internação específica
     * daquela organização, sem precisar ser o autor nem o dono. `clinic_owner` nunca tem
     * `medical-records.create` (seeder, `docs/rbac-clinica-autoria-e-staff.md`) — este método
     * nunca autoriza uma conta puramente administrativa.
     */
    public function hasClinicalAccessToOrganization(int $organizationId): bool
    {
        return $this->hasPermissionTo('medical-records.create') && $this->isActiveMemberOfOrganization($organizationId);
    }
}
