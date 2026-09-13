<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Favorite;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\Organization\CommercialOrganizationBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 3 do split Pessoa/Organização: tabelas do grupo COMERCIAL (`appointments`,
 * `favorites`, entre outras — ver `CommercialOrganizationBackfillService::TABLES`) ganham
 * `organization_id` preenchido a partir do vínculo `owner` em `organization_members`,
 * sem tocar em nenhum leitor.
 */
class CommercialOrganizationBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_sets_organization_id_from_owner_membership(): void
    {
        $owner = User::factory()->create(['user_type' => 'clinic']);
        $organization = Organization::factory()->create();
        OrganizationMember::factory()->owner()->for($organization, 'organization')->create([
            'user_id' => $owner->id,
        ]);
        $client = User::factory()->tutor()->create();

        $appointment = Appointment::create([
            'professional_id' => $owner->id,
            'client_id' => $client->id,
            'appointment_date' => now()->addDay(),
            'type' => 'consultation',
            'status' => 'scheduled',
        ]);
        $favorite = Favorite::create([
            'user_id' => $client->id,
            'professional_id' => $owner->id,
        ]);

        app(CommercialOrganizationBackfillService::class)->run();

        $this->assertSame($organization->id, $appointment->fresh()->organization_id);
        $this->assertSame($organization->id, $favorite->fresh()->organization_id);
    }

    public function test_solo_vet_freelancer_appointment_keeps_organization_id_null(): void
    {
        $vet = User::factory()->create(['user_type' => 'vet']);
        $client = User::factory()->tutor()->create();

        $appointment = Appointment::create([
            'professional_id' => $vet->id,
            'client_id' => $client->id,
            'appointment_date' => now()->addDay(),
            'type' => 'consultation',
            'status' => 'scheduled',
        ]);

        app(CommercialOrganizationBackfillService::class)->run();

        $this->assertNull($appointment->fresh()->organization_id);
    }

    public function test_backfill_is_idempotent(): void
    {
        $owner = User::factory()->create(['user_type' => 'clinic']);
        $organization = Organization::factory()->create();
        OrganizationMember::factory()->owner()->for($organization, 'organization')->create([
            'user_id' => $owner->id,
        ]);
        $client = User::factory()->tutor()->create();

        Appointment::create([
            'professional_id' => $owner->id,
            'client_id' => $client->id,
            'appointment_date' => now()->addDay(),
            'type' => 'consultation',
            'status' => 'scheduled',
        ]);

        $service = app(CommercialOrganizationBackfillService::class);
        $firstRun = $service->run();
        $secondRun = $service->run();

        $this->assertSame(1, $firstRun['appointments']);
        $this->assertSame(0, $secondRun['appointments']);
    }

    public function test_backfill_never_overwrites_an_already_set_organization_id(): void
    {
        $owner = User::factory()->create(['user_type' => 'clinic']);
        $originalOrganization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        OrganizationMember::factory()->owner()->for($otherOrganization, 'organization')->create([
            'user_id' => $owner->id,
        ]);
        $client = User::factory()->tutor()->create();

        $favorite = Favorite::create([
            'user_id' => $client->id,
            'professional_id' => $owner->id,
        ]);
        // `organization_id` não é mass-assignable de propósito (ver a migration de schema) —
        // simula um valor já preenchido por atribuição direta, não por `create()`.
        $favorite->organization_id = $originalOrganization->id;
        $favorite->save();

        app(CommercialOrganizationBackfillService::class)->run();

        $this->assertSame($originalOrganization->id, $favorite->fresh()->organization_id);
    }
}
