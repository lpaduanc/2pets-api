<?php

namespace App\Http\Controllers\Backoffice;

use App\Enums\BackofficePermission;
use App\Enums\BackofficeRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Backoffice\StaffResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Equipe do 2pets Admin: quem dá suporte ao tutor e ao 2pets Pro. Só quem tem
 * `backoffice.team.manage` chega aqui (rota), e as travas abaixo impedem que alguém se tranque
 * para fora ou derrube o último super admin.
 */
class TeamController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json([
            'roles' => BackofficeRole::options(),
            'permissions' => BackofficePermission::options(),
        ]);
    }

    public function index(): AnonymousResourceCollection
    {
        return StaffResource::collection($this->staff()->with('roles', 'permissions')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', Password::min(10)->letters()->numbers()],
            'role' => ['required', Rule::in(BackofficeRole::values())],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in(BackofficePermission::values())],
        ], ['email.unique' => 'Este e-mail já está cadastrado.']);

        $member = DB::transaction(function () use ($validated) {
            $member = User::create([
                'name' => $validated['name'],
                'email' => mb_strtolower($validated['email']),
                'phone' => $validated['phone'] ?? null,
                'password' => bcrypt($validated['password']),
                'role' => BackofficeRole::USERS_ROLE_COLUMN,
                'user_type' => null, // CHECK de `users.user_type` só aceita tipos de cadastro
                'email_verified_at' => now(),
                'profile_completed' => true,
                'registration_status' => 'approved',
            ]);

            $this->applyAccess($member, BackofficeRole::from($validated['role']), $validated['permissions'] ?? null);

            return $member;
        });

        $this->log($request, $member, 'staff.created', 'Membro da equipe cadastrado', ['role' => $validated['role']]);

        return (new StaffResource($member->fresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id): StaffResource
    {
        $member = $this->staff()->findOrFail($id);
        $isSelf = $member->id === $request->user()->id;

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($member->id)->withoutTrashed()],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'role' => ['sometimes', Rule::in(BackofficeRole::values())],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [Rule::in(BackofficePermission::values())],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $newRole = isset($validated['role']) ? BackofficeRole::from($validated['role']) : $this->roleOf($member);
        $deactivating = array_key_exists('is_active', $validated) && ! $validated['is_active'];

        if ($isSelf && $this->changesAccess($member, $newRole, $validated, $deactivating)) {
            abort(422, 'Você não pode alterar o próprio acesso.');
        }

        if ($this->roleOf($member) === BackofficeRole::SUPER_ADMIN
            && ($newRole !== BackofficeRole::SUPER_ADMIN || $deactivating)
            && $this->activeSuperAdminCount() <= 1) {
            abort(422, 'É preciso manter pelo menos um super admin ativo.');
        }

        DB::transaction(function () use ($member, $validated, $newRole, $deactivating) {
            $member->fill(collect($validated)->only(['name', 'email', 'phone'])->all());

            if (array_key_exists('is_active', $validated)) {
                $member->is_suspended = ! $validated['is_active'];
            }
            $member->save();

            if (isset($validated['role']) || array_key_exists('permissions', $validated)) {
                $this->applyAccess($member, $newRole, $validated['permissions'] ?? $this->directPermissions($member));
            }

            if ($deactivating) {
                $member->tokens()->delete();
            }
        });

        $this->log($request, $member, 'staff.updated', 'Acesso de membro da equipe alterado', $validated);

        return new StaffResource($member->fresh());
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $member = $this->staff()->findOrFail($id);
        $validated = $request->validate(['password' => ['required', 'string', Password::min(10)->letters()->numbers()]]);

        $member->forceFill(['password' => bcrypt($validated['password'])])->save();

        if ($member->id !== $request->user()->id) {
            $member->tokens()->delete();
        }

        $this->log($request, $member, 'staff.password_reset', 'Senha de membro da equipe redefinida');

        return response()->json(['message' => 'Senha redefinida.']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $member = $this->staff()->findOrFail($id);

        if ($member->id === $request->user()->id) {
            abort(422, 'Você não pode remover a própria conta.');
        }

        if ($this->roleOf($member) === BackofficeRole::SUPER_ADMIN && $this->activeSuperAdminCount() <= 1) {
            abort(422, 'É preciso manter pelo menos um super admin ativo.');
        }

        DB::transaction(function () use ($member) {
            $member->tokens()->delete();
            $member->syncRoles([]);
            $member->syncPermissions([]);
            $member->delete();
        });

        $this->log($request, $member, 'staff.removed', 'Membro da equipe removido');

        return response()->json(['message' => 'Membro removido.']);
    }

    /**
     * Papel único de equipe + permissões DIRETAS. Sem lista explícita, aplica o pacote do papel.
     *
     * @param  list<string>|null  $permissions
     */
    private function applyAccess(User $member, BackofficeRole $role, ?array $permissions): void
    {
        $otherRoles = $member->getRoleNames()->diff(BackofficeRole::values())->all();
        $member->syncRoles([...$otherRoles, $role->value]);

        $keep = $member->getDirectPermissions()->pluck('name')->diff(BackofficePermission::values())->all();
        $granted = $role === BackofficeRole::SUPER_ADMIN ? BackofficePermission::values() : ($permissions ?? $role->defaultPermissions());

        $member->syncPermissions([...$keep, ...array_values(array_unique($granted))]);
    }

    private function changesAccess(User $member, BackofficeRole $newRole, array $validated, bool $deactivating): bool
    {
        if ($deactivating || $newRole !== $this->roleOf($member)) {
            return true;
        }

        if ($newRole === BackofficeRole::SUPER_ADMIN || ! isset($validated['permissions'])) {
            return false;
        }

        $current = $this->directPermissions($member);
        sort($current);
        $requested = array_values(array_unique($validated['permissions']));
        sort($requested);

        return $current !== $requested;
    }

    /** @return list<string> */
    private function directPermissions(User $member): array
    {
        return array_values(array_intersect(BackofficePermission::values(), $member->getDirectPermissions()->pluck('name')->all()));
    }

    private function roleOf(User $member): ?BackofficeRole
    {
        return collect(BackofficeRole::cases())->first(fn (BackofficeRole $r) => $member->hasRole($r->value));
    }

    private function activeSuperAdminCount(): int
    {
        return User::role(BackofficeRole::SUPER_ADMIN->value)->where('is_suspended', false)->count();
    }

    /** @return Builder<User> */
    private function staff(): Builder
    {
        return User::query()->whereHas('roles', fn (Builder $q) => $q->whereIn('name', BackofficeRole::values()));
    }

    private function log(Request $request, User $member, string $event, string $description, array $properties = []): void
    {
        activity('backoffice')
            ->causedBy($request->user())
            ->performedOn($member)
            ->event($event)
            ->withProperties(collect($properties)->except('password')->all())
            ->log($description);
    }
}
