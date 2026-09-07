<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Trava de minimização de dado pessoal nos payloads de vet-access.
 *
 * Estes endpoints são servidos inclusive para solicitações ainda `pending` — momento em que
 * nenhuma das partes consentiu com nada. Antes desta trava, `veterinarian` e `grantor` usavam
 * `UserResource` inteiro e entregavam e-mail, telefone, data de nascimento, CNPJ, ocupação,
 * papéis e o **endereço completo com latitude/longitude** da contraparte.
 *
 * Se um destes testes falhar, alguém reintroduziu dado pessoal no payload — a correção é tirar
 * o campo, não afrouxar o teste.
 */
class VetAccessPayloadPrivacyTest extends TestCase
{
    use RefreshDatabase;

    /** Chaves que não podem aparecer em NENHUM nível dos blocos de pessoa. */
    private const FORBIDDEN_KEYS = [
        'cpf',
        'cnpj',
        'birth_date',
        'address',
        'email',
        'phone',
        'gender',
        'occupation',
        'roles',
        'registration_status',
        'is_suspended',
        'latitude',
        'longitude',
    ];

    private User $tutor;

    private User $vet;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create([
            'cpf' => '39053344705',
            'birth_date' => '1990-01-01',
            'address' => 'Rua Secreta, 42',
            'city' => 'São Paulo',
        ]);

        $this->vet = User::factory()->veterinarian()->create([
            'cpf' => '52998224725',
            'birth_date' => '1985-05-05',
            'address' => 'Rua do Consultório, 7',
            'phone' => '(11) 90000-0000',
        ]);

        Professional::create([
            'user_id' => $this->vet->id,
            'professional_type' => 'vet',
            'business_name' => 'Clínica Teste',
            'crmv' => '99999-SP',
            'crmv_state' => 'SP',
        ]);

        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Bella']);
    }

    public function test_tutor_pending_list_exposes_no_personal_data_of_the_vet(): void
    {
        PetVetAccess::factoryCreatePending($this->vet, $this->pet);

        Sanctum::actingAs($this->tutor);
        $payload = $this->getJson('/api/pet-vet-access/pending')->assertOk()->json('data.0');

        $this->assertPersonBlocksAreMinimal($payload);
    }

    public function test_pet_access_list_exposes_no_personal_data(): void
    {
        PetVetAccess::factoryCreatePending($this->vet, $this->pet)->accept(VetAccessLevel::READ);

        Sanctum::actingAs($this->tutor);
        $payload = $this->getJson("/api/pet-vet-access/pet/{$this->pet->id}")->assertOk()->json('data.0');

        $this->assertPersonBlocksAreMinimal($payload);
    }

    public function test_accept_response_exposes_no_personal_data(): void
    {
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet);

        Sanctum::actingAs($this->tutor);
        $payload = $this->postJson("/api/pet-vet-access/{$access->id}/accept", ['access_level' => 'read'])->assertOk()->json('data');

        $this->assertPersonBlocksAreMinimal($payload);
    }

    public function test_reject_response_exposes_no_personal_data(): void
    {
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet);

        Sanctum::actingAs($this->tutor);
        $payload = $this->postJson("/api/pet-vet-access/{$access->id}/reject")->assertOk()->json('data');

        $this->assertPersonBlocksAreMinimal($payload);
    }

    public function test_revoke_response_exposes_no_personal_data(): void
    {
        $access = PetVetAccess::factoryCreatePending($this->vet, $this->pet);
        $access->accept(VetAccessLevel::READ);

        Sanctum::actingAs($this->tutor);
        $payload = $this->postJson("/api/pet-vet-access/{$access->id}/revoke")->assertOk()->json('data');

        $this->assertPersonBlocksAreMinimal($payload);
    }

    public function test_conflict_response_exposes_no_personal_data_of_the_tutor(): void
    {
        PetVetAccess::factoryCreatePending($this->vet, $this->pet);

        Sanctum::actingAs($this->vet);
        $payload = $this->postJson('/api/pet-vet-access/request', ['pet_id' => $this->pet->id])
            ->assertStatus(409)
            ->json('data');

        $this->assertPersonBlocksAreMinimal($payload);
    }

    public function test_grant_response_exposes_no_personal_data(): void
    {
        Sanctum::actingAs($this->tutor);

        $payload = $this->postJson('/api/pet-vet-access/grant', [
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
        ])->assertCreated()->json('data');

        $this->assertPersonBlocksAreMinimal($payload);
    }

    public function test_vet_block_still_carries_what_the_tutor_needs_to_decide(): void
    {
        PetVetAccess::factoryCreatePending($this->vet, $this->pet);

        Sanctum::actingAs($this->tutor);
        $vetBlock = $this->getJson('/api/pet-vet-access/pending')->assertOk()->json('data.0.veterinarian');

        $this->assertSame(
            ['id', 'name', 'avatar_url', 'business_name', 'crmv', 'crmv_state', 'is_crmv_verified'],
            array_keys($vetBlock)
        );
        $this->assertSame($this->vet->name, $vetBlock['name']);
        $this->assertSame('99999-SP', $vetBlock['crmv']);
        $this->assertSame('SP', $vetBlock['crmv_state']);
        $this->assertFalse($vetBlock['is_crmv_verified']);
    }

    public function test_tutor_block_carries_only_identification(): void
    {
        PetVetAccess::factoryCreatePending($this->vet, $this->pet)->accept(VetAccessLevel::READ);

        Sanctum::actingAs($this->tutor);
        $grantor = $this->getJson("/api/pet-vet-access/pet/{$this->pet->id}")->assertOk()->json('data.0.grantor');

        $this->assertSame(['id', 'name', 'avatar_url'], array_keys($grantor));
    }

    /**
     * A lista do vet não deve disparar uma query de mídia/professional por linha.
     */
    public function test_pending_list_does_not_n_plus_one_on_participants(): void
    {
        foreach (range(1, 5) as $index) {
            $pet = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => "Pet {$index}"]);
            PetVetAccess::factoryCreatePending($this->vet, $pet);
        }

        Sanctum::actingAs($this->tutor);

        \DB::enableQueryLog();
        $this->getJson('/api/pet-vet-access/pending')->assertOk();
        $queryCount = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            12,
            $queryCount,
            "Listagem de pendências disparou {$queryCount} queries — suspeita de N+1 nos participantes."
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertPersonBlocksAreMinimal(array $payload): void
    {
        foreach (['veterinarian', 'grantor'] as $block) {
            $this->assertArrayHasKey($block, $payload, "Bloco `{$block}` sumiu do payload.");
            $this->assertPersonBlockHasNoPii($payload[$block], $block);
        }

        // Defesa em profundidade: nenhum valor sensível pode aparecer em lugar nenhum do
        // payload, mesmo fora dos dois blocos de pessoa.
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString($this->vet->cpf, $encoded, 'CPF do veterinário vazou.');
        $this->assertStringNotContainsString($this->tutor->cpf, $encoded, 'CPF do tutor vazou.');
        $this->assertStringNotContainsString('Rua do Consultório', $encoded, 'Endereço do veterinário vazou.');
        $this->assertStringNotContainsString('Rua Secreta', $encoded, 'Endereço do tutor vazou.');
        $this->assertStringNotContainsString($this->vet->email, $encoded, 'E-mail do veterinário vazou.');
    }

    /**
     * @param  array<string, mixed>|null  $block
     */
    private function assertPersonBlockHasNoPii(?array $block, string $label): void
    {
        $this->assertIsArray($block, "Bloco `{$label}` deveria ser um objeto.");

        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $block,
                "Dado pessoal `{$forbidden}` voltou ao bloco `{$label}` — remova o campo em vez de afrouxar este teste."
            );
        }
    }
}
