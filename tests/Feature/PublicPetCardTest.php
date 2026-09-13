<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/public/pet-card/{publicId} — the destination of the QR code
 * printed on a pet's carteirinha. Public and unauthenticated on purpose:
 * whoever finds a lost animal scans it without ever logging in.
 *
 * Regression coverage for the previously undefined `show()` method (500 on
 * every scan) and for the privacy leak it would have shipped with (tutor
 * e-mail/phone exposed even for a pet that was never reported lost).
 */
class PublicPetCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_404_for_an_unknown_public_id(): void
    {
        $this->getJson('/api/public/pet-card/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }

    public function test_it_does_not_require_authentication(): void
    {
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        $this->getJson("/api/public/pet-card/{$pet->public_id}")->assertOk();
    }

    public function test_it_exposes_only_the_minimum_needed_to_identify_a_pet_that_is_not_lost(): void
    {
        $tutor = User::factory()->tutor()->create(['phone' => '11999990000']);
        $pet = Pet::factory()->create([
            'user_id' => $tutor->id,
            'name' => 'Thor',
            'species' => 'dog',
            'breed' => 'Golden Retriever',
            'is_lost' => false,
        ]);

        $response = $this->getJson("/api/public/pet-card/{$pet->public_id}")->assertOk();

        $response->assertJsonPath('data.name', 'Thor')
            ->assertJsonPath('data.species', 'dog')
            ->assertJsonPath('data.breed', 'Golden Retriever')
            ->assertJsonPath('data.is_lost', false)
            ->assertJsonMissingPath('data.contact')
            ->assertJsonMissingPath('data.lost_alert_message');

        $response->assertJsonMissing(['email' => $tutor->email])
            ->assertJsonMissing(['phone' => $tutor->phone]);
    }

    public function test_it_exposes_tutor_contact_only_while_the_pet_is_reported_lost(): void
    {
        $tutor = User::factory()->tutor()->create(['name' => 'Maria Tutora', 'phone' => '11988887777']);
        $pet = Pet::factory()->create([
            'user_id' => $tutor->id,
            'is_lost' => true,
            'lost_alert_message' => 'Visto pela última vez perto do mercado.',
        ]);

        $response = $this->getJson("/api/public/pet-card/{$pet->public_id}")->assertOk();

        $response->assertJsonPath('data.is_lost', true)
            ->assertJsonPath('data.lost_alert_message', 'Visto pela última vez perto do mercado.')
            ->assertJsonPath('data.contact.name', 'Maria Tutora')
            ->assertJsonPath('data.contact.phone', '11988887777');

        $response->assertJsonMissing(['email' => $tutor->email]);
    }

    public function test_it_never_leaks_medical_record_fields(): void
    {
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create([
            'user_id' => $tutor->id,
            'is_lost' => true,
            'allergies' => ['Penicilina'],
            'chronic_diseases' => ['Diabetes'],
        ]);

        $response = $this->getJson("/api/public/pet-card/{$pet->public_id}")->assertOk();

        $response->assertJsonMissingPath('data.allergies')
            ->assertJsonMissingPath('data.chronic_diseases')
            ->assertJsonMissingPath('data.vaccinations')
            ->assertJsonMissingPath('data.microchip_number');
    }
}
