<?php

namespace Tests\Feature\Import;

use App\Models\User;
use App\Services\Import\ClientDuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 26 do backlog gap-simplesvet — cliente duplicado por CPF/e-mail/telefone, nesta
 * ordem de prioridade (regra 2: duplicidade nunca é automática, mas primeiro é preciso achar).
 */
class ClientDuplicateDetectorTest extends TestCase
{
    use RefreshDatabase;

    private ClientDuplicateDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new ClientDuplicateDetector;
    }

    public function test_finds_existing_client_by_cpf(): void
    {
        $existing = User::factory()->tutor()->create(['cpf' => '12345678909']);

        $found = $this->detector->findExisting(['cpf' => '12345678909', 'email' => null, 'phone' => null]);

        $this->assertTrue($existing->is($found));
    }

    public function test_falls_back_to_email_when_no_cpf_match(): void
    {
        $existing = User::factory()->tutor()->create(['email' => 'ana@example.com']);

        $found = $this->detector->findExisting(['cpf' => null, 'email' => 'ana@example.com', 'phone' => null]);

        $this->assertTrue($existing->is($found));
    }

    public function test_falls_back_to_phone_when_no_cpf_or_email_match(): void
    {
        $existing = User::factory()->tutor()->create(['phone' => '11999998888']);

        $found = $this->detector->findExisting(['cpf' => null, 'email' => null, 'phone' => '11999998888']);

        $this->assertTrue($existing->is($found));
    }

    public function test_returns_null_when_nothing_matches(): void
    {
        User::factory()->tutor()->create(['cpf' => '12345678909']);

        $found = $this->detector->findExisting(['cpf' => '98765432100', 'email' => null, 'phone' => null]);

        $this->assertNull($found);
    }
}
