<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The professional list endpoints used to return the whole table. They are now
 * paginated, but backwards-compatibly: `per_page` is optional, the default keeps
 * the current screens whole, and an out-of-range value is clamped instead of
 * rejected so no existing caller starts getting a 422.
 */
class ProfessionalListPaginationTest extends TestCase
{
    use RefreshDatabase;

    /** Ceiling enforced by PaginatesResults — nobody dumps the base in one page. */
    private const MAX_PER_PAGE = 200;

    /** Endpoint => default page size it must keep serving when `per_page` is absent. */
    private const ENDPOINT_DEFAULTS = [
        '/api/professional/invoices' => 200,
        '/api/professional/appointments' => 200,
        '/api/professional/clients' => 200,
        '/api/professional/prescriptions' => 100,
        '/api/professional/surgeries' => 100,
        '/api/professional/hospitalizations' => 100,
        '/api/professional/medical-records' => 100,
    ];

    private User $professional;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
        $this->client = User::factory()->tutor()->create();
        Pet::factory()->create(['user_id' => $this->client->id]);
    }

    public function test_every_professional_list_returns_a_paginated_envelope(): void
    {
        Sanctum::actingAs($this->professional);

        foreach (self::ENDPOINT_DEFAULTS as $endpoint => $expectedPerPage) {
            $response = $this->getJson($endpoint)->assertOk();

            $response->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
            $this->assertSame(
                $expectedPerPage,
                $response->json('meta.per_page'),
                "{$endpoint} changed its default page size — screens without a paginator would truncate."
            );
        }
    }

    public function test_the_default_page_does_not_truncate_the_current_screens(): void
    {
        $this->createInvoices(3);

        Sanctum::actingAs($this->professional);

        $this->getJson('/api/professional/invoices')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 1);
    }

    public function test_it_honours_an_explicit_per_page(): void
    {
        $this->createInvoices(3);

        Sanctum::actingAs($this->professional);

        $this->getJson('/api/professional/invoices?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_it_clamps_a_per_page_above_the_ceiling(): void
    {
        Sanctum::actingAs($this->professional);

        $this->getJson('/api/professional/invoices?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', self::MAX_PER_PAGE);
    }

    public function test_it_falls_back_to_the_default_for_a_meaningless_per_page(): void
    {
        Sanctum::actingAs($this->professional);

        $this->getJson('/api/professional/invoices?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', self::ENDPOINT_DEFAULTS['/api/professional/invoices']);
    }

    public function test_a_professional_never_pages_through_another_professionals_invoices(): void
    {
        $this->createInvoices(2);

        Sanctum::actingAs(User::factory()->professional()->create());

        $this->getJson('/api/professional/invoices')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    private function createInvoices(int $quantity): void
    {
        for ($index = 0; $index < $quantity; $index++) {
            Invoice::create([
                'professional_id' => $this->professional->id,
                'client_id' => $this->client->id,
                'invoice_number' => 'INV-'.$index,
                'issue_date' => now()->subDays($index)->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'items' => [['description' => 'Consulta', 'quantity' => 1, 'unit_price' => 100, 'total' => 100]],
                'subtotal' => 100,
                'total' => 100,
                'status' => 'pending',
            ]);
        }
    }
}
