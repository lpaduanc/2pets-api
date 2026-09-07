<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regra de negócio: quem decide o nível de acesso ao pet é o TUTOR, no aceite — nunca o
 * veterinário. O que o vet manda em `requested_access_level` é indicação de necessidade.
 *
 * Consequências cobertas aqui:
 *   - aceitar com nível diferente do pedido;
 *   - upgrade (vet com `read` pode pedir `full`), com a concessão anterior preservada;
 *   - pedido redundante (nível já coberto) continua 409;
 *   - tutor sobe e desce o nível pelo `PATCH /level`, sem nova solicitação;
 *   - o vet não decide nada: 403 no `accept` e no `PATCH /level`.
 */
class VetAccessLevelDecisionTest extends TestCase
{
    use RefreshDatabase;

    private const TUTOR_CPF = '39053344705';

    private User $tutor;

    private User $vet;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create(['cpf' => self::TUTOR_CPF]);
        $this->vet = User::factory()->veterinarian()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Bella']);
    }

    // ───────────────────────────────────────────────────────────
    // O pedido do vet não vincula
    // ───────────────────────────────────────────────────────────

    public function test_request_stores_the_vet_indication_without_granting_any_level(): void
    {
        Sanctum::actingAs($this->vet);

        $this->postJson('/api/pet-vet-access/request', [
            'pet_id' => $this->pet->id,
            'requested_access_level' => 'full',
        ])->assertCreated()
            ->assertJsonPath('data.requested_access_level', 'full')
            ->assertJsonPath('data.access_level', null);

        $this->assertDatabaseHas('pet_vet_accesses', [
            'pet_id' => $this->pet->id,
            'requested_access_level' => 'full',
            'access_level' => null,
            'status' => PetVetAccess::STATUS_PENDING,
        ]);
    }

    public function test_request_without_level_defaults_the_indication_to_read(): void
    {
        Sanctum::actingAs($this->vet);

        $this->postJson('/api/pet-vet-access/request', ['pet_id' => $this->pet->id])
            ->assertCreated()
            ->assertJsonPath('data.requested_access_level', 'read');
    }

    public function test_tutor_grants_a_level_different_from_the_one_requested(): void
    {
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet, VetAccessLevel::FULL);

        Sanctum::actingAs($this->tutor);
        $this->postJson("/api/pet-vet-access/{$access->id}/accept", ['access_level' => 'read'])
            ->assertOk()
            ->assertJsonPath('data.access_level', 'read')
            ->assertJsonPath('data.requested_access_level', 'full');

        $this->assertSame(VetAccessLevel::READ, $access->refresh()->access_level);
    }

    public function test_accept_without_a_level_is_rejected(): void
    {
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet);

        Sanctum::actingAs($this->tutor);
        $this->postJson("/api/pet-vet-access/{$access->id}/accept")
            ->assertStatus(422)
            ->assertJsonValidationErrors('access_level');

        $this->assertSame(PetVetAccess::STATUS_PENDING, $access->refresh()->status);
    }

    public function test_accept_with_an_unknown_level_is_rejected(): void
    {
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet);

        Sanctum::actingAs($this->tutor);
        $this->postJson("/api/pet-vet-access/{$access->id}/accept", ['access_level' => 'god'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('access_level');
    }

    /**
     * Contrato lido pelo app do tutor: os dois níveis vêm em chaves distintas e inequívocas —
     * `requested_access_level` é o contexto da decisão, `access_level` é a decisão. Enquanto
     * `pending`, o concedido é nulo de propósito.
     */
    public function test_tutor_pending_list_separates_the_indication_from_the_grant(): void
    {
        PetVetAccess::factoryCreatePending($this->vet, $this->pet, VetAccessLevel::FULL);

        Sanctum::actingAs($this->tutor);
        $payload = $this->getJson('/api/pet-vet-access/pending')->assertOk()->json('data.0');

        $this->assertArrayHasKey('requested_access_level', $payload);
        $this->assertSame('full', $payload['requested_access_level']);
        $this->assertNull($payload['access_level']);
        $this->assertSame(PetVetAccess::STATUS_PENDING, $payload['status']);
    }

    // ───────────────────────────────────────────────────────────
    // Upgrade
    // ───────────────────────────────────────────────────────────

    public function test_vet_with_read_can_request_full(): void
    {
        $this->grantLevel(VetAccessLevel::READ);

        Sanctum::actingAs($this->vet);
        $this->postJson('/api/pet-vet-access/request', [
            'pet_id' => $this->pet->id,
            'requested_access_level' => 'full',
        ])->assertCreated()
            ->assertJsonPath('data.requested_access_level', 'full');

        $this->assertSame(1, $this->countWithStatus(PetVetAccess::STATUS_PENDING));
        $this->assertSame(1, $this->countWithStatus(PetVetAccess::STATUS_ACCEPTED));
    }

    public function test_vet_with_full_gets_conflict_when_requesting_full(): void
    {
        $this->grantLevel(VetAccessLevel::FULL);

        Sanctum::actingAs($this->vet);
        $this->postJson('/api/pet-vet-access/request', [
            'pet_id' => $this->pet->id,
            'requested_access_level' => 'full',
        ])->assertStatus(409)->assertJsonPath('data.status', 'accepted');
    }

    public function test_vet_with_full_gets_conflict_when_requesting_read(): void
    {
        $this->grantLevel(VetAccessLevel::FULL);

        Sanctum::actingAs($this->vet);
        $response = $this->postJson('/api/pet-vet-access/request', [
            'pet_id' => $this->pet->id,
            'requested_access_level' => 'read',
        ])->assertStatus(409);

        $this->assertStringContainsString('já cobre o nível Leitura solicitado', $response->json('message'));
    }

    public function test_pending_upgrade_blocks_another_request(): void
    {
        $this->grantLevel(VetAccessLevel::READ);

        Sanctum::actingAs($this->vet);
        $this->postJson('/api/pet-vet-access/request', [
            'pet_id' => $this->pet->id,
            'requested_access_level' => 'write',
        ])->assertCreated();

        $this->postJson('/api/pet-vet-access/request', [
            'pet_id' => $this->pet->id,
            'requested_access_level' => 'full',
        ])->assertStatus(409)->assertJsonPath('data.status', 'pending');
    }

    public function test_accepting_an_upgrade_preserves_the_previous_grant_for_audit(): void
    {
        $first = $this->grantLevel(VetAccessLevel::READ);

        Sanctum::actingAs($this->vet);
        $upgradeId = $this->postJson('/api/pet-vet-access/request', [
            'pet_id' => $this->pet->id,
            'requested_access_level' => 'full',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->tutor);
        $this->postJson("/api/pet-vet-access/{$upgradeId}/accept", ['access_level' => 'full'])
            ->assertOk()
            ->assertJsonPath('data.access_level', 'full');

        $first->refresh();
        $this->assertSame(PetVetAccess::STATUS_SUPERSEDED, $first->status);
        $this->assertSame(VetAccessLevel::READ, $first->access_level, 'O nível anterior não pode ser reescrito.');
        $this->assertNotNull($first->granted_at);
        $this->assertNotNull($first->superseded_at);
        $this->assertSame($upgradeId, $first->superseded_by_id);
        $this->assertFalse($first->is_active);

        $this->assertSame(1, $this->countWithStatus(PetVetAccess::STATUS_ACCEPTED));
    }

    // ───────────────────────────────────────────────────────────
    // PATCH /level — o tutor não fica preso à escolha anterior
    // ───────────────────────────────────────────────────────────

    public function test_tutor_downgrades_an_accepted_access(): void
    {
        $access = $this->grantLevel(VetAccessLevel::FULL);

        Sanctum::actingAs($this->tutor);
        $response = $this->patchJson("/api/pet-vet-access/{$access->id}/level", ['access_level' => 'read'])
            ->assertOk()
            ->assertJsonPath('data.access_level', 'read')
            ->assertJsonPath('data.status', 'accepted');

        $access->refresh();
        $this->assertSame(PetVetAccess::STATUS_SUPERSEDED, $access->status);
        $this->assertSame(VetAccessLevel::FULL, $access->access_level);
        $this->assertSame($response->json('data.id'), $access->superseded_by_id);
        $this->assertSame(1, $this->countWithStatus(PetVetAccess::STATUS_ACCEPTED));
    }

    public function test_tutor_upgrades_an_accepted_access_without_a_new_request(): void
    {
        $access = $this->grantLevel(VetAccessLevel::READ);

        Sanctum::actingAs($this->tutor);
        $this->patchJson("/api/pet-vet-access/{$access->id}/level", ['access_level' => 'full'])
            ->assertOk()
            ->assertJsonPath('data.access_level', 'full');

        $this->assertSame(0, $this->countWithStatus(PetVetAccess::STATUS_PENDING));
    }

    public function test_changing_to_the_same_level_creates_no_extra_row(): void
    {
        $access = $this->grantLevel(VetAccessLevel::WRITE);

        Sanctum::actingAs($this->tutor);
        $this->patchJson("/api/pet-vet-access/{$access->id}/level", ['access_level' => 'write'])
            ->assertOk()
            ->assertJsonPath('data.id', $access->id);

        $this->assertSame(1, PetVetAccess::query()->where('pet_id', $this->pet->id)->count());
    }

    public function test_level_change_on_a_revoked_access_is_rejected(): void
    {
        $access = $this->grantLevel(VetAccessLevel::READ);
        $access->revoke($this->tutor->id, 'Troquei de veterinário');

        Sanctum::actingAs($this->tutor);
        $this->patchJson("/api/pet-vet-access/{$access->id}/level", ['access_level' => 'full'])
            ->assertStatus(422);
    }

    public function test_level_change_requires_a_level(): void
    {
        $access = $this->grantLevel(VetAccessLevel::READ);

        Sanctum::actingAs($this->tutor);
        $this->patchJson("/api/pet-vet-access/{$access->id}/level")
            ->assertStatus(422)
            ->assertJsonValidationErrors('access_level');
    }

    /** A trilha inteira precisa reconstruir "read de X até Y, full a partir de Y". */
    public function test_audit_trail_reconstructs_every_level_window(): void
    {
        $first = $this->grantLevel(VetAccessLevel::READ);

        Sanctum::actingAs($this->tutor);
        $second = $this->patchJson("/api/pet-vet-access/{$first->id}/level", ['access_level' => 'write'])
            ->assertOk()->json('data.id');
        $third = $this->patchJson("/api/pet-vet-access/{$second}/level", ['access_level' => 'full'])
            ->assertOk()->json('data.id');

        $chain = PetVetAccess::query()
            ->where('pet_id', $this->pet->id)
            ->where('veterinarian_id', $this->vet->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(
            ['read', 'write', 'full'],
            $chain->pluck('access_level')->map(fn (VetAccessLevel $level): string => $level->value)->all()
        );
        $this->assertSame([$second, $third, null], $chain->pluck('superseded_by_id')->all());
        $this->assertSame(
            [PetVetAccess::STATUS_SUPERSEDED, PetVetAccess::STATUS_SUPERSEDED, PetVetAccess::STATUS_ACCEPTED],
            $chain->pluck('status')->all()
        );
        $this->assertNotNull($chain[0]->superseded_at, 'Sem superseded_at não dá para dizer até quando o nível valeu.');
    }

    // ───────────────────────────────────────────────────────────
    // Downgrade não pode virar escalonamento
    // ───────────────────────────────────────────────────────────

    public function test_vet_cannot_accept_his_own_request(): void
    {
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet, VetAccessLevel::READ);

        Sanctum::actingAs($this->vet);
        $this->postJson("/api/pet-vet-access/{$access->id}/accept", ['access_level' => 'full'])
            ->assertForbidden();

        $this->assertSame(PetVetAccess::STATUS_PENDING, $access->refresh()->status);
        $this->assertNull($access->access_level);
    }

    public function test_vet_cannot_change_the_level_of_his_own_access(): void
    {
        $access = $this->grantLevel(VetAccessLevel::READ);

        Sanctum::actingAs($this->vet);
        $this->patchJson("/api/pet-vet-access/{$access->id}/level", ['access_level' => 'full'])
            ->assertForbidden();

        $this->assertSame(VetAccessLevel::READ, $access->refresh()->access_level);
    }

    public function test_another_tutor_cannot_change_the_level(): void
    {
        $access = $this->grantLevel(VetAccessLevel::READ);

        Sanctum::actingAs(User::factory()->tutor()->create());
        $this->patchJson("/api/pet-vet-access/{$access->id}/level", ['access_level' => 'full'])
            ->assertForbidden();
    }

    // ───────────────────────────────────────────────────────────
    // Garantias do banco
    // ───────────────────────────────────────────────────────────

    public function test_database_rejects_two_pending_rows_for_the_same_pair(): void
    {
        PetVetAccess::factoryCreatePending($this->vet, $this->pet);

        $this->expectException(QueryException::class);
        PetVetAccess::factoryCreatePending($this->vet, $this->pet, VetAccessLevel::FULL);
    }

    public function test_database_rejects_two_accepted_rows_for_the_same_pair(): void
    {
        $this->grantLevel(VetAccessLevel::READ);

        $this->expectException(QueryException::class);
        $this->grantLevel(VetAccessLevel::FULL);
    }

    public function test_database_accepts_one_pending_alongside_one_accepted(): void
    {
        $this->grantLevel(VetAccessLevel::READ);
        PetVetAccess::factoryCreatePending($this->vet, $this->pet, VetAccessLevel::FULL);

        $this->assertSame(2, PetVetAccess::query()->where('pet_id', $this->pet->id)->count());
    }

    // ───────────────────────────────────────────────────────────
    // Busca: níveis visíveis e can_request sensível a upgrade
    // ───────────────────────────────────────────────────────────

    public function test_search_reports_granted_level_and_allows_upgrade(): void
    {
        $this->grantLevel(VetAccessLevel::READ);

        $this->searchAsVet()
            ->assertJsonPath('data.0.vet_access.status', 'accepted')
            ->assertJsonPath('data.0.vet_access.granted_access_level', 'read')
            ->assertJsonPath('data.0.vet_access.pending_request_level', null)
            ->assertJsonPath('data.0.vet_access.can_request', true);
    }

    public function test_search_blocks_a_new_request_when_full_is_already_granted(): void
    {
        $this->grantLevel(VetAccessLevel::FULL);

        $this->searchAsVet()
            ->assertJsonPath('data.0.vet_access.granted_access_level', 'full')
            ->assertJsonPath('data.0.vet_access.can_request', false);
    }

    public function test_search_reports_the_pending_upgrade_alongside_the_granted_level(): void
    {
        $this->grantLevel(VetAccessLevel::READ);
        PetVetAccess::factoryCreatePending($this->vet, $this->pet, VetAccessLevel::FULL);

        $this->searchAsVet()
            ->assertJsonPath('data.0.vet_access.status', 'accepted')
            ->assertJsonPath('data.0.vet_access.granted_access_level', 'read')
            ->assertJsonPath('data.0.vet_access.pending_request_level', 'full')
            ->assertJsonPath('data.0.vet_access.can_request', false);
    }

    public function test_search_reports_no_granted_level_while_only_pending(): void
    {
        PetVetAccess::factoryCreatePending($this->vet, $this->pet, VetAccessLevel::WRITE);

        $this->searchAsVet()
            ->assertJsonPath('data.0.vet_access.status', 'pending')
            ->assertJsonPath('data.0.vet_access.granted_access_level', null)
            ->assertJsonPath('data.0.vet_access.pending_request_level', 'write')
            ->assertJsonPath('data.0.vet_access.can_request', false);
    }

    public function test_search_still_runs_a_constant_number_of_queries_with_both_rows(): void
    {
        foreach (['Luna', 'Max', 'Nina', 'Toddy'] as $name) {
            $pet = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => $name]);
            PetVetAccess::factoryCreatePending($this->vet, $pet)->accept(VetAccessLevel::READ);
            PetVetAccess::factoryCreatePending($this->vet, $pet, VetAccessLevel::FULL);
        }

        Sanctum::actingAs($this->vet);

        DB::enableQueryLog();
        $this->getJson('/api/pets/search?tutor_cpf='.self::TUTOR_CPF)->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(6, $queryCount, "Busca disparou {$queryCount} queries — suspeita de N+1.");
    }

    // ───────────────────────────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────────────────────────

    private function grantLevel(VetAccessLevel $level): PetVetAccess
    {
        return PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'granted_by' => $this->tutor->id,
            'access_level' => $level,
            'requested_access_level' => VetAccessLevel::READ,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'granted_at' => now(),
            'responded_at' => now(),
            'is_active' => true,
        ]);
    }

    private function countWithStatus(string $status): int
    {
        return PetVetAccess::query()
            ->where('pet_id', $this->pet->id)
            ->where('veterinarian_id', $this->vet->id)
            ->where('status', $status)
            ->count();
    }

    private function searchAsVet(): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->vet);

        return $this->getJson('/api/pets/search?tutor_cpf='.self::TUTOR_CPF)->assertOk();
    }
}
