<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\PetWeightHistory;
use App\Models\User;
use App\Services\Pet\PetWeightHistoryWriter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** O peso gravado ao finalizar atendimento/internação fica no dia de negócio (São Paulo), não no dia UTC. */
class PetWeightHistoryWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_weight_recorded_at_night_stays_on_the_sao_paulo_day(): void
    {
        Carbon::setTestNow('2026-11-07 01:30:00 UTC'); // 22h30 de 06/11 em São Paulo
        $vet = User::factory()->professional()->create();
        $pet = Pet::factory()->create();

        app(PetWeightHistoryWriter::class)->recordIfPresent($pet->id, $vet, '12.40', now());

        $this->assertSame('2026-11-06', PetWeightHistory::sole()->measured_at->toDateString());
        Carbon::setTestNow();
    }
}
