<?php

namespace Tests\Feature;

use App\Models\ClientRelationshipProfile;
use App\Models\Pet;
use App\Models\User;
use App\Services\Crm\ClientSegmentQueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contrato `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`. Vet volante (sem
 * organização) — o par `organization_id = null` + `professional_id` do próprio profissional.
 */
class ClientSegmentQueryBuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private ClientSegmentQueryBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
        $this->builder = app(ClientSegmentQueryBuilder::class);
    }

    public function test_combined_species_pathology_and_lifecycle_filter_returns_the_right_set(): void
    {
        $matchingClient = $this->createProfileWithPet(
            lifecycleStage: 'quiet_3_6m',
            species: 'dog',
            chronicDiseases: ['diabetes canina'],
        );

        $this->createProfileWithPet(lifecycleStage: 'quiet_3_6m', species: 'cat', chronicDiseases: ['diabetes felina']);
        $this->createProfileWithPet(lifecycleStage: 'returned_recently', species: 'dog', chronicDiseases: ['diabetes canina']);

        $results = $this->builder->build($this->professional, [
            'lifecycle_stage' => ['quiet_3_6m'],
            'species' => 'dog',
            'pathology' => 'diabetes',
        ])->get();

        $this->assertCount(1, $results);
        $this->assertSame($matchingClient->id, $results->first()->client_id);
    }

    public function test_archived_clients_are_excluded_unless_include_archived_is_true(): void
    {
        $archived = $this->createProfileWithPet(lifecycleStage: 'quiet_1_3m', species: 'dog', archived: true);
        $active = $this->createProfileWithPet(lifecycleStage: 'quiet_1_3m', species: 'dog');

        $defaultResults = $this->builder->build($this->professional, [])->pluck('client_id');
        $this->assertTrue($defaultResults->contains($active->id));
        $this->assertFalse($defaultResults->contains($archived->id));

        $withArchived = $this->builder->build($this->professional, ['include_archived' => true])->pluck('client_id');
        $this->assertTrue($withArchived->contains($archived->id));
    }

    private function createProfileWithPet(string $lifecycleStage, string $species, array $chronicDiseases = [], bool $archived = false): User
    {
        $client = User::factory()->tutor()->create();

        Pet::factory()->create([
            'user_id' => $client->id,
            'species' => $species,
            'chronic_diseases' => $chronicDiseases,
        ]);

        ClientRelationshipProfile::create([
            'organization_id' => null,
            'professional_id' => $this->professional->id,
            'client_id' => $client->id,
            'lifecycle_stage' => $lifecycleStage,
            'archived_at' => $archived ? now() : null,
            'recalculated_at' => now(),
        ]);

        return $client;
    }
}
