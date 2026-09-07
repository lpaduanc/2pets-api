<?php

namespace Tests\Feature;

use App\Enums\ProfessionalType;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Autorização no 2pets é decidida SEMPRE pelo papel Spatie. Conta profissional criada sem
 * papel loga normalmente e toma 403 em toda funcionalidade que deveria ter — foi essa a causa
 * de "o veterinário busca o pet pelo CPF do tutor e não vem nada".
 */
class ProfessionalRoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['tutor', 'vet_freelancer', 'clinic_owner', 'petshop_owner'] as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }
    }

    public function test_every_professional_type_maps_to_an_existing_role(): void
    {
        $existingRoles = Role::query()->pluck('name')->all();

        foreach (ProfessionalType::cases() as $type) {
            $this->assertContains(
                $type->defaultRoleName(),
                $existingRoles,
                "O tipo {$type->value} aponta para um papel que não existe."
            );
        }
    }

    /**
     * Regressão: o match antigo em `AuthController` cobria só tutor/vet/clinic/petshop —
     * laboratory, pet_hotel, grooming e training nasciam sem papel nenhum.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('professionalTypeProvider')]
    public function test_registration_assigns_a_role_for_every_professional_type(string $userType, string $expectedRole): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Profissional Teste',
            'email' => $userType.'@exemplo.test',
            'phone' => '11999990000',
            'user_type' => $userType,
            'password' => 'senha-forte-123',
            'password_confirmation' => 'senha-forte-123',
        ]);

        $response->assertSuccessful();

        $user = User::where('email', $userType.'@exemplo.test')->firstOrFail();
        $this->assertTrue(
            $user->hasRole($expectedRole),
            "Cadastro do tipo {$userType} deveria receber o papel {$expectedRole}."
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function professionalTypeProvider(): array
    {
        $cases = [];

        foreach (ProfessionalType::cases() as $type) {
            $cases[$type->value] = [$type->value, $type->defaultRoleName()];
        }

        return $cases;
    }

    public function test_professional_without_any_role_cannot_reach_the_vet_search(): void
    {
        $roleless = User::factory()->create(['role' => 'professional', 'user_type' => 'vet']);
        $roleless->syncRoles([]);

        Sanctum::actingAs($roleless);

        $this->getJson('/api/pets/search?tutor_cpf=39053344705')->assertForbidden();
    }

    public function test_vet_role_unlocks_the_search(): void
    {
        $tutor = User::factory()->tutor()->create(['cpf' => '39053344705']);
        Pet::factory()->create(['user_id' => $tutor->id, 'name' => 'Bella']);

        $vet = User::factory()->veterinarian()->create();
        Sanctum::actingAs($vet);

        $this->getJson('/api/pets/search?tutor_cpf=39053344705')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Bella');
    }
}
