<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /api/lost-pets` — a lista permanente que o menu "Pets Perdidos" mostra.
 *
 * A regra que amarra esta tela ao alerta: o que aparece em `nearby` tem que ser
 * exatamente o conjunto de alertas que notificaria este usuario (raio de cada
 * alerta, nao um raio fixo do observador), e nada sai da lista ate o tutor dono
 * encerrar.
 */
class LostPetMenuTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN_LAT = -23.5505;

    private const ORIGIN_LNG = -46.6333;

    public function test_it_lists_my_own_active_alerts_separately_from_the_neighbourhood(): void
    {
        $me = $this->userAtKmNorth('tutor', 0);
        $myPet = Pet::factory()->create(['user_id' => $me->id, 'name' => 'Thor']);

        $neighbour = $this->userAtKmNorth('tutor', 3);
        $neighbourPet = Pet::factory()->create(['user_id' => $neighbour->id, 'name' => 'Luna']);

        $this->openAlertAs($neighbour, $neighbourPet);
        $this->openAlertAs($me, $myPet);

        Sanctum::actingAs($me);
        $response = $this->getJson('/api/lost-pets')->assertOk();

        $response->assertJsonPath('meta.mine_count', 1)
            ->assertJsonPath('meta.nearby_count', 1)
            ->assertJsonPath('meta.total_count', 2)
            ->assertJsonPath('meta.has_reference_point', true)
            ->assertJsonPath('data.mine.0.pet.name', 'Thor')
            ->assertJsonPath('data.mine.0.is_mine', true)
            ->assertJsonPath('data.nearby.0.pet.name', 'Luna')
            ->assertJsonPath('data.nearby.0.is_mine', false);
    }

    public function test_a_professional_sees_the_lost_pets_of_the_region(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id, 'name' => 'Mel']);
        $this->openAlertAs($tutor, $pet);

        $vet = $this->userAtKmNorth('professional', 12);

        Sanctum::actingAs($vet);
        $this->getJson('/api/lost-pets')
            ->assertOk()
            ->assertJsonPath('meta.nearby_count', 1)
            ->assertJsonPath('data.nearby.0.pet.name', 'Mel');
    }

    /**
     * O corte e o raio do ALERTA. Um alerta de 30 km nao pode aparecer para
     * quem esta a 45 km — essa pessoa nunca recebeu o push dele.
     */
    public function test_an_alert_whose_radius_does_not_reach_me_is_not_listed(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);
        $this->openAlertAs($tutor, $pet);

        $distantVet = $this->userAtKmNorth('professional', 45);

        Sanctum::actingAs($distantVet);
        $this->getJson('/api/lost-pets')
            ->assertOk()
            ->assertJsonPath('meta.nearby_count', 0);
    }

    public function test_a_user_without_a_registered_address_is_told_there_is_no_reference_point(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);
        $this->openAlertAs($tutor, $pet);

        $nomad = User::factory()->professional()->create(['latitude' => null, 'longitude' => null]);

        Sanctum::actingAs($nomad);
        $this->getJson('/api/lost-pets')
            ->assertOk()
            ->assertJsonPath('meta.has_reference_point', false)
            ->assertJsonPath('meta.nearby_count', 0);
    }

    public function test_explicit_coordinates_let_a_user_look_at_another_region(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);
        $this->openAlertAs($tutor, $pet);

        $distantVet = $this->userAtKmNorth('professional', 200);

        Sanctum::actingAs($distantVet);
        $this->getJson('/api/lost-pets?latitude='.self::ORIGIN_LAT.'&longitude='.self::ORIGIN_LNG)
            ->assertOk()
            ->assertJsonPath('meta.nearby_count', 1);
    }

    public function test_the_alert_stays_listed_until_the_owner_closes_it(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);
        $alertId = $this->openAlertAs($tutor, $pet);

        $neighbour = $this->userAtKmNorth('tutor', 2);

        Sanctum::actingAs($neighbour);
        $this->getJson('/api/lost-pets')->assertOk()->assertJsonPath('meta.nearby_count', 1);

        Sanctum::actingAs($tutor);
        $this->postJson("/api/lost-pets/{$alertId}/found")->assertOk();

        $this->assertFalse($pet->fresh()->is_lost);

        Sanctum::actingAs($neighbour);
        $this->getJson('/api/lost-pets')->assertOk()->assertJsonPath('meta.nearby_count', 0);

        Sanctum::actingAs($tutor);
        $this->getJson('/api/lost-pets')->assertOk()->assertJsonPath('meta.mine_count', 0);
    }

    public function test_only_the_owner_can_close_an_alert(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);
        $alertId = $this->openAlertAs($tutor, $pet);

        $stranger = $this->userAtKmNorth('professional', 2);

        Sanctum::actingAs($stranger);
        $this->postJson("/api/lost-pets/{$alertId}/found")->assertNotFound();

        $this->assertDatabaseHas('lost_pet_alerts', ['id' => $alertId, 'status' => 'active']);
    }

    public function test_marking_lost_twice_does_not_duplicate_the_alert(): void
    {
        $tutor = $this->userAtKmNorth('tutor', 0);
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        Sanctum::actingAs($tutor);
        $this->postJson("/api/pet-card/{$pet->id}/mark-lost")->assertOk();
        $this->postJson("/api/pet-card/{$pet->id}/mark-lost")->assertOk();

        $this->getJson('/api/lost-pets')->assertOk()->assertJsonPath('meta.mine_count', 1);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/lost-pets')->assertUnauthorized();
    }

    // ------------------------------------------------------------------

    private function latAtKmNorth(float $km): float
    {
        return self::ORIGIN_LAT + ($km / 110.57);
    }

    private function userAtKmNorth(string $state, float $km): User
    {
        return User::factory()->{$state}()->create([
            'latitude' => $this->latAtKmNorth($km),
            'longitude' => self::ORIGIN_LNG,
        ]);
    }

    private function openAlertAs(User $tutor, Pet $pet): int
    {
        Sanctum::actingAs($tutor);

        return $this->postJson("/api/pet-card/{$pet->id}/mark-lost")
            ->assertOk()
            ->json('alert_id');
    }
}
