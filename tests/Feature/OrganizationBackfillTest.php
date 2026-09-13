<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Professional;
use App\Models\User;
use App\Services\Organization\OrganizationBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 1 do split Pessoa/Organização: toda conta `users.user_type` de negócio (clínica,
 * laboratório, petshop, hotel, banho e tosa, adestramento) que já tinha uma linha em
 * `professionals` precisa ganhar uma `Organization` + um vínculo `owner` em
 * `organization_members` — sem duplicar em reexecuções e sem tocar em vet volante, que
 * continua sendo só pessoa física.
 */
class OrganizationBackfillTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: OrganizationType}>
     */
    public static function businessTypes(): array
    {
        return array_map(
            fn (OrganizationType $type): array => [$type],
            OrganizationType::cases()
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('businessTypes')]
    public function test_creates_organization_and_owner_membership_for_each_business_type(OrganizationType $type): void
    {
        $user = $this->createBusinessUser($type);
        $professional = $this->createProfessionalProfile($user, $type);

        app(OrganizationBackfillService::class)->run();

        $organization = Organization::where('cnpj', $professional->cnpj)->first();

        $this->assertNotNull($organization, "Organization não foi criada para {$type->value}.");
        $this->assertSame($type, $organization->organization_type);
        $this->assertSame($professional->business_name, $organization->business_name);
        $this->assertSame($user->address, $organization->address);
        $this->assertSame($user->city, $organization->city);

        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);
    }

    public function test_backfill_does_not_duplicate_when_run_twice(): void
    {
        $user = $this->createBusinessUser(OrganizationType::CLINIC);
        $this->createProfessionalProfile($user, OrganizationType::CLINIC);

        $service = app(OrganizationBackfillService::class);
        $firstRunCount = $service->run();
        $secondRunCount = $service->run();

        $this->assertSame(1, $firstRunCount);
        $this->assertSame(0, $secondRunCount);
        $this->assertSame(1, Organization::count());
        $this->assertSame(1, OrganizationMember::count());
    }

    public function test_vet_freelancer_does_not_get_an_organization(): void
    {
        $vet = User::factory()->create(['user_type' => 'vet']);
        Professional::factory()->veterinarian()->create(['user_id' => $vet->id]);

        app(OrganizationBackfillService::class)->run();

        $this->assertSame(0, Organization::count());
        $this->assertSame(0, OrganizationMember::count());
    }

    public function test_a_person_can_belong_to_multiple_organizations(): void
    {
        $vet = User::factory()->create(['user_type' => 'vet']);
        $firstClinic = Organization::factory()->create();
        $secondClinic = Organization::factory()->create();

        OrganizationMember::factory()->for($firstClinic, 'organization')->create(['user_id' => $vet->id]);
        OrganizationMember::factory()->for($secondClinic, 'organization')->create(['user_id' => $vet->id]);

        $this->assertCount(2, $vet->organizations);
    }

    public function test_owner_membership_does_not_grant_veterinarian_role(): void
    {
        $owner = User::factory()->create(['user_type' => 'clinic']);
        $organization = Organization::factory()->create();

        OrganizationMember::factory()->owner()->for($organization, 'organization')->create([
            'user_id' => $owner->id,
        ]);

        $this->assertFalse($owner->fresh()->isVeterinarian());
    }

    private function createBusinessUser(OrganizationType $type): User
    {
        return User::factory()->create([
            'user_type' => $type->value,
            'address' => 'Rua Teste',
            'number' => '100',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01000-000',
            'latitude' => -23.55052,
            'longitude' => -46.633308,
        ]);
    }

    private function createProfessionalProfile(User $user, OrganizationType $type): Professional
    {
        return Professional::factory()->create([
            'user_id' => $user->id,
            'professional_type' => $type->value,
            'business_name' => 'Negócio '.$type->value,
            'cnpj' => fake()->unique()->numerify(str_repeat('#', 14)),
        ]);
    }
}
