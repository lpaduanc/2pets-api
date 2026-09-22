<?php

namespace Tests\Feature\Import;

use App\Enums\Import\ImportEntity;
use App\Exceptions\Import\ImportOutOfOrderException;
use App\Models\Pet;
use App\Models\ProfessionalClient;
use App\Models\User;
use App\Services\Import\ImportDependencyOrderGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 26 do backlog gap-simplesvet, regra 1: "ordem de dependência é bloqueante, não
 * sugestão" — catálogos (23) → clientes → pets → produtos/serviços → histórico.
 */
class ImportDependencyOrderGuardTest extends TestCase
{
    use RefreshDatabase;

    private ImportDependencyOrderGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new ImportDependencyOrderGuard;
    }

    public function test_clients_can_always_be_imported_first(): void
    {
        $professional = User::factory()->professional()->create();

        $this->guard->ensureCanImport(ImportEntity::CLIENTS, $professional->id);

        $this->expectNotToPerformAssertions();
    }

    public function test_importing_pets_before_any_client_exists_is_blocked(): void
    {
        $professional = User::factory()->professional()->create();

        $this->expectException(ImportOutOfOrderException::class);

        $this->guard->ensureCanImport(ImportEntity::PETS, $professional->id);
    }

    public function test_importing_pets_is_allowed_once_a_client_exists(): void
    {
        $professional = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create();
        ProfessionalClient::create(['professional_id' => $professional->id, 'client_id' => $client->id]);

        $this->guard->ensureCanImport(ImportEntity::PETS, $professional->id);

        $this->expectNotToPerformAssertions();
    }

    public function test_out_of_order_exception_renders_as_422_with_explanation(): void
    {
        $exception = new ImportOutOfOrderException(ImportEntity::PETS);

        $response = $exception->render(request());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Importe clientes primeiro', (string) $response->getContent());
    }

    public function test_importing_vaccinations_before_any_pet_exists_is_blocked(): void
    {
        $professional = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create();
        ProfessionalClient::create(['professional_id' => $professional->id, 'client_id' => $client->id]);

        $this->expectException(ImportOutOfOrderException::class);

        $this->guard->ensureCanImport(ImportEntity::VACCINATIONS, $professional->id);
    }

    public function test_importing_vaccinations_is_allowed_once_a_pet_exists(): void
    {
        $professional = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create();
        ProfessionalClient::create(['professional_id' => $professional->id, 'client_id' => $client->id]);
        Pet::factory()->create(['user_id' => $client->id]);

        $this->guard->ensureCanImport(ImportEntity::VACCINATIONS, $professional->id);

        $this->expectNotToPerformAssertions();
    }

    public function test_vaccination_out_of_order_exception_mentions_pets_not_clients(): void
    {
        $exception = new ImportOutOfOrderException(ImportEntity::VACCINATIONS, ImportEntity::PETS);

        $response = $exception->render(request());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Importe pets primeiro', (string) $response->getContent());
    }

    public function test_products_can_be_imported_without_any_client_or_pet(): void
    {
        $professional = User::factory()->professional()->create();

        $this->guard->ensureCanImport(ImportEntity::PRODUCTS, $professional->id);

        $this->expectNotToPerformAssertions();
    }
}
