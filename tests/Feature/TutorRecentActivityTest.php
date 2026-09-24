<?php

namespace Tests\Feature;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Payment;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /api/dashboard/stats` → `data.recentActivity`: feed unificado da Início do tutor
 * (`TutorActivityFeedService` + `app/Services/Dashboard/ActivityFeed/*`). Teste escrito
 * conforme a regra do projeto: NÃO executado via `artisan test`.
 */
class TutorRecentActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_a_pet_appears_in_recent_activity(): void
    {
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id, 'name' => 'Rex', 'weight' => 10]);
        Sanctum::actingAs($tutor);

        $pet->update(['weight' => 12.5]);

        $response = $this->getJson('/api/dashboard/stats');
        $response->assertOk();

        $item = $this->findActivity($response->json('data.recentActivity'), 'pet');

        $this->assertNotNull($item, 'a alteração do pet deveria aparecer no feed');
        $this->assertSame('Rex', $item['pet_name']);
        $this->assertStringContainsString('peso', mb_strtolower($item['description']));
        $this->assertSame("/tutor/pets/{$pet->id}/overview", $item['link']);
    }

    public function test_appointment_confirmation_appears_in_recent_activity(): void
    {
        $tutor = User::factory()->tutor()->create();
        $vet = User::factory()->professional()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);
        $appointment = Appointment::create([
            'professional_id' => $vet->id,
            'client_id' => $tutor->id,
            'pet_id' => $pet->id,
            'appointment_date' => now()->addDay(),
            'appointment_time' => '10:00',
            'type' => 'consultation',
            'status' => 'pending',
        ]);
        Sanctum::actingAs($tutor);

        $appointment->update(['status' => 'confirmed', 'confirmed_at' => now()]);

        $response = $this->getJson('/api/dashboard/stats');
        $response->assertOk();

        $item = $this->findActivity($response->json('data.recentActivity'), 'appointment');

        $this->assertNotNull($item, 'a confirmação do agendamento deveria aparecer no feed');
        $this->assertSame('Consulta confirmada', $item['title']);
        $this->assertSame('success', $item['tone']);
    }

    public function test_another_tutors_pet_activity_never_leaks_into_my_feed(): void
    {
        $tutor = User::factory()->tutor()->create();
        Pet::factory()->create(['user_id' => $tutor->id, 'name' => 'MeuPet']);

        $otherTutor = User::factory()->tutor()->create();
        $otherPet = Pet::factory()->create(['user_id' => $otherTutor->id, 'name' => 'PetAlheio']);
        $otherPet->update(['weight' => 99]);

        Sanctum::actingAs($tutor);

        $response = $this->getJson('/api/dashboard/stats');
        $response->assertOk();

        $petNames = collect($response->json('data.recentActivity'))->pluck('pet_name')->filter();

        $this->assertFalse($petNames->contains('PetAlheio'), 'atividade de pet de outro tutor não pode aparecer no feed');
    }

    public function test_recent_activity_respects_default_limit_and_descending_order(): void
    {
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);
        Sanctum::actingAs($tutor);

        // 7 alterações distintas, cada uma com timestamp próprio, mais antigo primeiro.
        foreach (range(1, 7) as $step) {
            $pet->update(['weight' => 10 + $step]);
            $this->backdateLatestActivity($pet->id, now()->subMinutes(7 - $step));
        }

        $response = $this->getJson('/api/dashboard/stats');
        $response->assertOk();
        $activity = $response->json('data.recentActivity');

        $this->assertCount(5, $activity, 'default deve ser 5 itens, mesmo com mais eventos disponíveis');

        $timestamps = collect($activity)->pluck('created_at')->map(fn ($iso) => Carbon::parse($iso)->timestamp);
        $this->assertSame($timestamps->sortDesc()->values()->all(), $timestamps->values()->all(), 'feed deve vir ordenado do mais recente para o mais antigo');
    }

    public function test_activity_limit_query_parameter_is_honored_and_capped(): void
    {
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);
        Sanctum::actingAs($tutor);

        foreach (range(1, 7) as $step) {
            $pet->update(['weight' => 10 + $step]);
        }

        $response = $this->getJson('/api/dashboard/stats?activity_limit=7');
        $response->assertOk();
        $this->assertCount(7, $response->json('data.recentActivity'));

        $response = $this->getJson('/api/dashboard/stats?activity_limit=999');
        $response->assertOk();
        $this->assertLessThanOrEqual(20, count($response->json('data.recentActivity')));
    }

    public function test_paid_deposit_appears_as_a_purchase_in_recent_activity(): void
    {
        $tutor = User::factory()->tutor()->create();
        $vet = User::factory()->professional()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id, 'name' => 'Bolinha']);
        $appointment = Appointment::create([
            'professional_id' => $vet->id,
            'client_id' => $tutor->id,
            'pet_id' => $pet->id,
            'appointment_date' => now()->addDay(),
            'appointment_time' => '09:00',
            'type' => 'consultation',
            'status' => 'confirmed',
        ]);

        Payment::create([
            'appointment_id' => $appointment->id,
            'user_id' => $tutor->id,
            'gateway' => 'manual',
            'purpose' => PaymentPurpose::DEPOSIT->value,
            'method' => 'pix',
            'amount' => 50,
            'status' => PaymentStatus::PAID->value,
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($tutor);

        $response = $this->getJson('/api/dashboard/stats');
        $response->assertOk();

        $item = $this->findActivity($response->json('data.recentActivity'), 'purchase');

        $this->assertNotNull($item, 'o sinal pago deveria aparecer no feed como compra');
        $this->assertSame('Sinal pago', $item['title']);
        $this->assertSame('Bolinha', $item['pet_name']);
    }

    /**
     * @param  list<array<string, mixed>>  $activity
     * @return array<string, mixed>|null
     */
    private function findActivity(array $activity, string $type): ?array
    {
        foreach ($activity as $item) {
            if ($item['type'] === $type) {
                return $item;
            }
        }

        return null;
    }

    /**
     * `Activity::create` sempre grava `created_at = now()` — para testar ordenação real é
     * preciso reescrever o carimbo por fora do Eloquent, mesmo truque de
     * `DashboardAggregationsTest::createInvoice()`.
     */
    private function backdateLatestActivity(int $petId, Carbon $createdAt): void
    {
        DB::table('activity_log')
            ->where('subject_type', Pet::class)
            ->where('subject_id', $petId)
            ->orderByDesc('id')
            ->limit(1)
            ->update(['created_at' => $createdAt]);
    }
}
