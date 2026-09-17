<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /professional/prescriptions?sort=` — ordenação server-side.
 *
 * A tela já mandava `sort` antes de o servidor entendê-lo: ordenava no cliente sobre a página
 * carregada e precisava avisar "X de Y carregadas" para não mentir. Estes testes travam que a
 * ordem vem do banco, vale sobre a lista inteira e nunca devolve 422 por valor estranho.
 */
class PrescriptionSortTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
        $tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $tutor->id]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->professional->id,
            'granted_by' => $tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($this->professional);
    }

    public function test_recent_is_the_default_and_lists_the_newest_prescription_first(): void
    {
        $ids = $this->createDatedPrescriptions();

        $this->assertSame(
            [$ids['newest'], $ids['middle'], $ids['oldest']],
            $this->listedIds('/api/professional/prescriptions')
        );
    }

    public function test_recent_matches_the_default_ordering(): void
    {
        $this->createDatedPrescriptions();

        $this->assertSame(
            $this->listedIds('/api/professional/prescriptions'),
            $this->listedIds('/api/professional/prescriptions?sort=recent')
        );
    }

    public function test_oldest_reverses_the_prescription_date_order(): void
    {
        $ids = $this->createDatedPrescriptions();

        $this->assertSame(
            [$ids['oldest'], $ids['middle'], $ids['newest']],
            $this->listedIds('/api/professional/prescriptions?sort=oldest')
        );
    }

    public function test_validity_orders_by_expiry_date_and_pushes_prescriptions_without_a_deadline_last(): void
    {
        $expiringSoon = $this->createPrescription(['valid_until' => '2026-04-01']);
        $expiringLater = $this->createPrescription(['valid_until' => '2026-12-31']);
        $noDeadline = $this->createPrescription(['valid_until' => null]);

        $this->assertSame(
            [$expiringSoon->id, $expiringLater->id, $noDeadline->id],
            $this->listedIds('/api/professional/prescriptions?sort=validity'),
            'Receita sem validade não pode encabeçar a lista de "vence primeiro".'
        );
    }

    public function test_an_unknown_sort_value_falls_back_to_recent_instead_of_failing(): void
    {
        $ids = $this->createDatedPrescriptions();

        $this->assertSame(
            [$ids['newest'], $ids['middle'], $ids['oldest']],
            $this->listedIds('/api/professional/prescriptions?sort=por-cor-do-pet')
        );
    }

    public function test_an_empty_sort_value_falls_back_to_recent_instead_of_failing(): void
    {
        $ids = $this->createDatedPrescriptions();

        $this->assertSame(
            [$ids['newest'], $ids['middle'], $ids['oldest']],
            $this->listedIds('/api/professional/prescriptions?sort=')
        );
    }

    public function test_pagination_never_repeats_or_skips_a_row_when_dates_are_identical(): void
    {
        $sameDay = ['prescription_date' => '2026-03-05', 'valid_until' => '2026-06-05'];
        $created = collect(range(1, 6))->map(fn (): int => $this->createPrescription($sameDay)->id)->all();

        foreach (['recent', 'oldest', 'validity'] as $sort) {
            $paged = array_merge(
                $this->listedIds("/api/professional/prescriptions?sort={$sort}&per_page=2&page=1"),
                $this->listedIds("/api/professional/prescriptions?sort={$sort}&per_page=2&page=2"),
                $this->listedIds("/api/professional/prescriptions?sort={$sort}&per_page=2&page=3"),
            );

            sort($paged);

            $this->assertSame($created, $paged, "Paginação instável na ordenação '{$sort}'.");
        }
    }

    public function test_sorting_applies_to_the_whole_list_not_just_the_first_page(): void
    {
        $this->createPrescription(['prescription_date' => '2026-01-01']);
        $this->createPrescription(['prescription_date' => '2026-02-01']);
        $lastEmitted = $this->createPrescription(['prescription_date' => '2026-03-01']);

        $this->assertSame(
            [$lastEmitted->id],
            $this->listedIds('/api/professional/prescriptions?sort=recent&per_page=1'),
            'A primeira página precisa trazer o topo da lista inteira, não o topo do que já foi carregado.'
        );
    }

    /**
     * @return array<string, int>
     */
    private function createDatedPrescriptions(): array
    {
        return [
            'middle' => $this->createPrescription(['prescription_date' => '2026-02-10'])->id,
            'newest' => $this->createPrescription(['prescription_date' => '2026-03-20'])->id,
            'oldest' => $this->createPrescription(['prescription_date' => '2026-01-05'])->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPrescription(array $overrides = []): Prescription
    {
        $prescription = Prescription::create(array_merge([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'prescription_date' => '2026-03-05',
        ], $overrides));

        $prescription->items()->create(['position' => 1, 'commercial_name' => 'Amoxicilina', 'dose_value' => 250, 'dose_unit' => 'mg']);

        return $prescription;
    }

    /**
     * @return list<int>
     */
    private function listedIds(string $uri): array
    {
        $response = $this->getJson($uri);
        $response->assertOk();

        return array_column($response->json('data'), 'id');
    }
}
