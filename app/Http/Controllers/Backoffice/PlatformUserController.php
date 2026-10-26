<?php

namespace App\Http\Controllers\Backoffice;

use App\Enums\BackofficeRole;
use App\Enums\ProfessionalType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Backoffice\PlatformUserResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Backoffice\ImpersonationService;
use App\Services\Organization\UserRoleReconciler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Tutores e contas do 2pets Pro vistos pela equipe do 2pets Admin: lista unificada, detalhe,
 * cadastro assistido (quem travou no cadastro do app), ajustes de conta e "Acessar como".
 *
 * Nunca lista nem altera conta da equipe — essas moram em `TeamController`.
 */
class PlatformUserController extends Controller
{
    private const PLATFORM_ROLES = ['tutor', 'professional', 'company'];

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ImpersonationService $impersonation,
        private readonly UserRoleReconciler $roleReconciler,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'app' => ['nullable', Rule::in(['tutor', 'pro'])],
            'user_type' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'deactivated'])],
            'email_verified' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $users = $this->platformUsers()
            ->withCount('pets')
            ->when($filters['app'] ?? null, fn (Builder $q, string $app) => $app === 'tutor'
                ? $q->where('role', 'tutor')
                : $q->whereIn('role', ['professional', 'company']))
            ->when($filters['user_type'] ?? null, fn (Builder $q, string $type) => $q->where('user_type', $type))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => match ($status) {
                'suspended' => $q->where('is_suspended', true),
                'deactivated' => $q->where('is_suspended', false)->whereNotNull('deactivated_at'),
                'active' => $q->where('is_suspended', false)->whereNull('deactivated_at'),
            })
            ->when(isset($filters['email_verified']), fn (Builder $q) => $filters['email_verified']
                ? $q->whereNotNull('email_verified_at')
                : $q->whereNull('email_verified_at'))
            ->when($filters['search'] ?? null, function (Builder $q, string $term) {
                $like = '%'.mb_strtolower($term).'%';
                $digits = preg_replace('/\D/', '', $term);

                $q->where(function (Builder $w) use ($like, $digits) {
                    $w->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$like]);

                    if (strlen($digits) >= 4) {
                        $w->orWhere('phone', 'like', "%{$digits}%")
                            ->orWhere('cpf', 'like', "%{$digits}%")
                            ->orWhere('cnpj', 'like', "%{$digits}%");
                    }

                    if (ctype_digit($digits) && $digits !== '') {
                        $w->orWhere('id', (int) $digits);
                    }
                });
            })
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25);

        return PlatformUserResource::collection($users)->response();
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->platformUsers()
            ->withCount('pets')
            ->with(['professional', 'company', 'media', 'activeOrganizationMemberships.organization', 'pets:id,user_id,name,species'])
            ->findOrFail($id);

        return response()->json([
            'data' => array_merge(
                (new UserResource($user))->resolve($request),
                (new PlatformUserResource($user))->resolve($request),
                [
                    'admin_notes' => $user->admin_notes,
                    'pets' => $user->pets->map(fn ($pet) => [
                        'id' => $pet->id,
                        'name' => $pet->name,
                        'species' => $pet->species,
                    ])->values(),
                    'organizations' => $user->activeOrganizationMemberships->map(fn ($m) => [
                        'id' => $m->organization?->id,
                        'name' => $m->organization?->name,
                        'role' => $m->role instanceof \BackedEnum ? $m->role->value : $m->role,
                    ])->values(),
                    'tokens_count' => $user->tokens()->count(),
                ],
            ),
        ]);
    }

    /**
     * Cadastro assistido. Mesmo resultado do `POST /register` (papel por `user_type`, reconciliação
     * Spatie), mas com o e-mail já verificado por padrão — a equipe está atendendo a pessoa — e
     * senha opcional: sem senha, uma é gerada e devolvida UMA vez para ser repassada.
     * O profissional continua com `profile_completed = false`; o "Acessar como" leva a equipe ao
     * wizard do 2pets Pro para terminar o cadastro junto com ele.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->withoutTrashed()],
            'phone' => ['required', 'string', 'max:20'],
            'user_type' => ['required', Rule::in(['tutor', ...ProfessionalType::values()])],
            'password' => ['nullable', 'string', Password::min(8)],
            'cpf' => ['nullable', 'string', 'max:14'],
            'mark_email_verified' => ['nullable', 'boolean'],
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'email.unique' => 'Este e-mail já está cadastrado.',
        ]);

        $generatedPassword = empty($validated['password']) ? Str::password(12, symbols: false) : null;
        $isTutor = $validated['user_type'] === 'tutor';

        $user = DB::transaction(function () use ($validated, $generatedPassword, $isTutor, $request) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => mb_strtolower($validated['email']),
                'phone' => $validated['phone'],
                'cpf' => $validated['cpf'] ?? null,
                'user_type' => $validated['user_type'],
                'role' => $isTutor ? 'tutor' : 'professional',
                'password' => bcrypt($validated['password'] ?? $generatedPassword),
                'email_verified_at' => ($validated['mark_email_verified'] ?? true) ? now() : null,
                'profile_completed' => false,
                'registration_status' => 'approved',
                'admin_notes' => $validated['admin_notes'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $this->roleReconciler->reconcile($user);

            return $user;
        });

        activity('backoffice')
            ->causedBy($request->user())
            ->performedOn($user)
            ->event('platform_user.created')
            ->withProperties(['user_type' => $user->user_type])
            ->log('Cadastro feito pelo 2pets Admin');

        return response()->json([
            'data' => (new PlatformUserResource($user))->resolve($request),
            'generated_password' => $generatedPassword,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->platformUsers()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)->withoutTrashed()],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'admin_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        if (isset($validated['email'])) {
            $validated['email'] = mb_strtolower($validated['email']);
        }

        $user->update($validated);

        $this->log($request, $user, 'platform_user.updated', 'Conta editada pelo 2pets Admin', array_keys($validated));

        return response()->json(['data' => (new PlatformUserResource($user->fresh()))->resolve($request)]);
    }

    public function suspend(Request $request, int $id): JsonResponse
    {
        $user = $this->platformUsers()->findOrFail($id);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        $user->update([
            'is_suspended' => true,
            'admin_notes' => trim(($user->admin_notes ? $user->admin_notes."\n" : '').'Suspenso: '.$reason),
        ]);
        // Suspensão vale já: as sessões abertas (inclusive impersonações) caem junto.
        $user->tokens()->delete();

        $this->log($request, $user, 'platform_user.suspended', 'Conta suspensa', ['reason' => $reason]);

        return response()->json(['data' => (new PlatformUserResource($user->fresh()))->resolve($request)]);
    }

    public function activate(Request $request, int $id): JsonResponse
    {
        $user = $this->platformUsers()->findOrFail($id);
        $user->update(['is_suspended' => false]);

        $this->log($request, $user, 'platform_user.activated', 'Suspensão removida');

        return response()->json(['data' => (new PlatformUserResource($user->fresh()))->resolve($request)]);
    }

    /** Desbloqueia o login de quem não recebeu/achou o e-mail de verificação. */
    public function verifyEmail(Request $request, int $id): JsonResponse
    {
        $user = $this->platformUsers()->findOrFail($id);

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->log($request, $user, 'platform_user.email_verified', 'E-mail marcado como verificado');
        }

        return response()->json(['data' => (new PlatformUserResource($user->fresh()))->resolve($request)]);
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $user = $this->platformUsers()->findOrFail($id);
        $validated = $request->validate(['password' => ['nullable', 'string', Password::min(8)]]);

        $password = $validated['password'] ?? Str::password(12, symbols: false);
        $user->forceFill(['password' => bcrypt($password)])->save();
        $user->tokens()->delete();

        $this->log($request, $user, 'platform_user.password_reset', 'Senha redefinida pelo 2pets Admin');

        return response()->json([
            'message' => 'Senha redefinida. As sessões abertas foram encerradas.',
            'generated_password' => empty($validated['password']) ? $password : null,
        ]);
    }

    public function impersonate(Request $request, int $id): JsonResponse
    {
        $user = $this->platformUsers()->findOrFail($id);

        return response()->json($this->impersonation->start($request->user(), $user));
    }

    /** @return Builder<User> */
    private function platformUsers(): Builder
    {
        return User::query()
            ->whereIn('role', self::PLATFORM_ROLES)
            ->whereDoesntHave('roles', fn (Builder $q) => $q->whereIn('name', BackofficeRole::values()));
    }

    private function log(Request $request, User $user, string $event, string $description, array $properties = []): void
    {
        activity('backoffice')
            ->causedBy($request->user())
            ->performedOn($user)
            ->event($event)
            ->withProperties($properties)
            ->log($description);
    }
}
