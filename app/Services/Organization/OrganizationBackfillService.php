<?php

namespace App\Services\Organization;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 do split Pessoa/Organização: toda conta de negócio (clínica, laboratório, petshop,
 * hotel, banho e tosa, adestramento) que hoje É ao mesmo tempo a pessoa que loga e a empresa
 * ganha uma `Organization` própria + um vínculo `owner` em `organization_members`.
 *
 * Idempotente: a checagem é "este usuário já é owner de alguma organização?", nunca o CNPJ —
 * CNPJ pode estar ausente em cadastro legado incompleto, e um `whereNull` ali quebraria a
 * garantia de não duplicar.
 *
 * Vet volante (`ProfessionalType::VET`) nunca entra aqui: continua sendo só pessoa física,
 * sem CNPJ — por isso a busca é por `professional_type` dentro de `OrganizationType::values()`,
 * que deliberadamente não inclui `vet`.
 */
final class OrganizationBackfillService
{
    private const CHUNK_SIZE = 200;

    /**
     * @return int quantidade de organizações criadas nesta execução
     */
    /**
     * @return int quantidade de organizações criadas nesta execução
     */
    public function run(): int
    {
        $organizationsCreated = 0;

        // Auditoria linha a linha não faz sentido em migração de dado: geraria um registro em
        // `activity_log` por organização herdada — 38 mil no ambiente de benchmark — só para
        // dizer "a migration rodou". O registro da migração é a própria migration. Foi esse
        // insert extra que estourou o Postgres na primeira execução contra o banco de dev.
        activity()->withoutLogs(function () use (&$organizationsCreated): void {
            // `withoutGlobalScope(SoftDeletingScope::class)`: esta é uma migration de DADO,
            // datada de antes de `professionals` ganhar `deleted_at` (soft delete adicionado
            // em onda posterior). Numa migração do zero, o Eloquent aplica o escopo global do
            // model ATUAL nesta query independente da posição na linha do tempo das
            // migrations de schema — sem isto, `chunkById` filtra por uma coluna que ainda
            // não existe neste ponto da migração e quebra `SQLSTATE[42703]`.
            Professional::withoutGlobalScope(SoftDeletingScope::class)
                ->whereIn('professional_type', OrganizationType::values())
                ->with('user')
                ->chunkById(self::CHUNK_SIZE, function (Collection $professionals) use (&$organizationsCreated): void {
                    $organizationsCreated += $this->backfillChunk($professionals);
                });
        });

        return $organizationsCreated;
    }

    /**
     * Uma transação por LOTE, nunca por linha.
     *
     * `DB::transaction()` aninhado dentro da transação que a migration já abre vira SAVEPOINT,
     * e um savepoint por registro esgota os slots de subtransação do Postgres por volta de
     * 13 mil linhas — `SQLSTATE[53200] out of shared memory / max_locks_per_transaction`.
     */
    private function backfillChunk(Collection $professionals): int
    {
        $pending = $this->rejectAlreadyBackfilled($professionals);

        if ($pending->isEmpty()) {
            return 0;
        }

        $technicalResponsibleIds = $this->resolveTechnicalResponsibleProfessionalIds($pending);

        return DB::transaction(function () use ($pending, $technicalResponsibleIds): int {
            foreach ($pending as $professional) {
                $organization = $this->createOrganization($professional, $professional->user, $technicalResponsibleIds);
                $this->createOwnerMembership($organization, $professional->user);
            }

            return $pending->count();
        });
    }

    /**
     * Idempotência em UMA query por lote, em vez de uma por linha. O `unique` por usuário
     * cobre o caso de duas linhas em `professionals` do mesmo dono caírem no mesmo lote —
     * sem ele, o mesmo usuário ganharia duas organizações.
     *
     * O `user !== null` NÃO é só defesa contra FK órfã: `User` usa SoftDeletes, então a
     * relação já exclui conta excluída, e conta excluída não deve ganhar organização nova.
     * No backfill do banco de dev isso pulou 1.928 de 38.574 linhas, todas de usuário com
     * `deleted_at` preenchido e nenhuma de conta ativa. É o comportamento desejado — não
     * troque por `withTrashed()` achando que é bug.
     *
     * @param  Collection<int, Professional>  $professionals
     * @return Collection<int, Professional>
     */
    private function rejectAlreadyBackfilled(Collection $professionals): Collection
    {
        $candidates = $professionals
            ->filter(fn (Professional $professional): bool => $professional->user !== null)
            ->unique(fn (Professional $professional): int => $professional->user->id);

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $alreadyOwners = OrganizationMember::query()
            ->whereIn('user_id', $candidates->map(fn (Professional $professional): int => $professional->user->id))
            ->where('role', OrganizationMember::ROLE_OWNER)
            ->pluck('user_id')
            ->all();

        return $candidates->reject(
            fn (Professional $professional): bool => in_array($professional->user->id, $alreadyOwners, true)
        );
    }

    /**
     * Resolve o RT de todo o lote numa query só, devolvendo `users.id => professionals.id`.
     *
     * @param  Collection<int, Professional>  $professionals
     * @return array<int, int>
     */
    private function resolveTechnicalResponsibleProfessionalIds(Collection $professionals): array
    {
        $responsibleUserIds = $professionals
            ->pluck('technical_responsible_id')
            ->filter()
            ->unique();

        if ($responsibleUserIds->isEmpty()) {
            return [];
        }

        return Professional::withoutGlobalScope(SoftDeletingScope::class)
            ->whereIn('user_id', $responsibleUserIds)
            ->pluck('id', 'user_id')
            ->all();
    }

    /**
     * @param  array<int, int>  $technicalResponsibleIds
     */
    private function createOrganization(Professional $professional, User $user, array $technicalResponsibleIds): Organization
    {
        return Organization::create([
            'organization_type' => OrganizationType::from($professional->professional_type->value),
            'business_name' => $professional->business_name,
            'cnpj' => $professional->cnpj,
            'description' => $professional->description,
            'opening_hours' => $professional->opening_hours,
            'closing_hours' => $professional->closing_hours,
            'working_days' => $professional->working_days,
            'service_radius_km' => $professional->service_radius_km,
            'services_offered' => $professional->services_offered,
            'products_sold' => $professional->products_sold,
            ...$this->addressFrom($user),
            ...$this->technicalResponsibleFrom($professional, $technicalResponsibleIds),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function addressFrom(User $user): array
    {
        return [
            'address' => $user->address,
            'number' => $user->number,
            'complement' => $user->complement,
            'neighborhood' => $user->neighborhood,
            'city' => $user->city,
            'state' => $user->state,
            'zip_code' => $user->zip_code,
            'latitude' => $user->latitude,
            'longitude' => $user->longitude,
        ];
    }

    /**
     * Responsável técnico: preferimos vincular a FK para `professionals` (o RT tem perfil
     * próprio na plataforma); os campos de texto ficam como fallback quando o cadastro
     * legado só registrou nome/CRMV em texto livre, sem conta vinculada.
     *
     * `technical_responsible_verified` começa sempre `false` — verificação é ato manual do
     * admin, nunca inferido no backfill.
     *
     * @param  array<int, int>  $technicalResponsibleIds  mapa `users.id => professionals.id`
     * @return array<string, mixed>
     */
    private function technicalResponsibleFrom(Professional $professional, array $technicalResponsibleIds): array
    {
        return [
            'technical_responsible_professional_id' => $technicalResponsibleIds[$professional->technical_responsible_id] ?? null,
            'technical_responsible_name' => $professional->technical_responsible_name,
            'technical_responsible_crmv' => $professional->technical_responsible_crmv,
            'technical_responsible_crmv_state' => $professional->technical_responsible_crmv_state,
            'technical_responsible_verified' => false,
        ];
    }

    private function createOwnerMembership(Organization $organization, User $user): OrganizationMember
    {
        return OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => OrganizationMember::ROLE_OWNER,
            'hire_date' => $user->created_at?->toDateString() ?? now()->toDateString(),
            'is_active' => true,
        ]);
    }
}
