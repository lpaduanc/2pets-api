<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateRegistrationException;
use App\Models\Company;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `DuplicateRegistrationException::fromQueryException()` é o mapeamento usado tanto pelo
 * handler global (`bootstrap/app.php`, para a race condition que sobrevive ao `Rule::unique`
 * do Form Request) quanto por `RegistrationDraftController` (que não passa por Form Request
 * nenhum). Testado aqui contra `QueryException` REAL — provocada por uma colisão de verdade
 * no banco — para não arriscar divergir do formato exato da mensagem que o Postgres devolve.
 */
class DuplicateRegistrationExceptionMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_maps_users_email_unique_violation_to_email_field(): void
    {
        User::factory()->create(['email' => 'duplicado@example.com']);

        $exception = $this->captureQueryException(
            fn () => User::factory()->create(['email' => 'duplicado@example.com'])
        );

        $duplicate = DuplicateRegistrationException::fromQueryException($exception);

        $this->assertNotNull($duplicate);
        $this->assertSame(422, $duplicate->render()->getStatusCode());
        $this->assertSame(['fields' => ['email'], 'action' => 'login'], $duplicate->render()->getData(true)['duplicate']);
    }

    public function test_maps_professionals_crmv_unique_violation_to_crmv_field(): void
    {
        Professional::factory()->veterinarian()->create(['crmv' => 'CRMV/SP 11111']);

        $exception = $this->captureQueryException(
            fn () => Professional::factory()->veterinarian()->create(['crmv' => 'CRMV/SP 11111'])
        );

        $duplicate = DuplicateRegistrationException::fromQueryException($exception);

        $this->assertNotNull($duplicate);
        $this->assertSame(['crmv' => ['Este CRMV já está cadastrado.']], $duplicate->render()->getData(true)['errors']);
    }

    public function test_maps_companies_cnpj_unique_violation_to_cnpj_field(): void
    {
        Company::factory()->create(['cnpj' => '11222333000181']);

        $exception = $this->captureQueryException(
            fn () => Company::factory()->create(['cnpj' => '11222333000181'])
        );

        $duplicate = DuplicateRegistrationException::fromQueryException($exception);

        $this->assertNotNull($duplicate);
        $this->assertSame(['fields' => ['cnpj'], 'action' => 'login'], $duplicate->render()->getData(true)['duplicate']);
    }

    /**
     * Constraint não mapeada (ex.: FK, CHECK) devolve `null` — quem chamou segue com o
     * tratamento padrão do framework, nunca um contrato de duplicidade fabricado.
     */
    public function test_returns_null_for_a_non_unique_constraint_violation(): void
    {
        $user = User::factory()->create();

        // Insert via query builder cru — contorna o cast de enum do Eloquent, que
        // rejeitaria o valor inválido antes mesmo de chegar ao banco. O objetivo aqui é
        // provocar a CHECK constraint (23514), não a unique (23505).
        $exception = $this->captureQueryException(fn () => DB::table('professionals')->insert([
            'user_id' => $user->id,
            'professional_type' => 'nao_existe',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertNull(DuplicateRegistrationException::fromQueryException($exception));
    }

    private function captureQueryException(callable $callback): QueryException
    {
        try {
            $callback();
        } catch (QueryException $exception) {
            return $exception;
        }

        $this->fail('Esperava QueryException, nenhuma foi lançada.');
    }
}
