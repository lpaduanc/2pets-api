<?php

namespace Tests\Feature;

use App\Enums\DeactivationReason;
use App\Models\Appointment;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Item 5 da decisão de desativação: um cliente com a própria conta desativada continua na
 * lista do profissional que o atendeu, só marcado como inativo — mas um cliente anonimizado
 * pelo direito ao esquecimento (LGPD) nunca aparece.
 */
class ProfessionalClientDeactivationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function createLinkedTutor(User $professional): User
    {
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        Appointment::create([
            'professional_id' => $professional->id,
            'client_id' => $tutor->id,
            'pet_id' => $pet->id,
            'appointment_date' => now()->addWeek()->toDateString(),
            'appointment_time' => '10:00:00',
        ]);

        return $tutor;
    }

    public function test_deactivated_client_still_appears_marked_as_inactive(): void
    {
        $professional = User::factory()->professional()->create();
        $tutor = $this->createLinkedTutor($professional);

        $tutor->forceFill([
            'deactivated_at' => now(),
            'deactivation_reason' => DeactivationReason::NOT_USING,
            'deactivated_by' => $tutor->id,
        ])->save();

        Sanctum::actingAs($professional);

        $response = $this->getJson('/api/professional/clients')->assertOk();

        $client = collect($response->json('data'))->firstWhere('id', $tutor->id);

        $this->assertNotNull($client, 'Cliente desativado precisa continuar na lista.');
        $this->assertFalse($client['is_active']);
    }

    public function test_active_only_filter_hides_deactivated_clients(): void
    {
        $professional = User::factory()->professional()->create();
        $activeTutor = $this->createLinkedTutor($professional);
        $deactivatedTutor = $this->createLinkedTutor($professional);

        $deactivatedTutor->forceFill([
            'deactivated_at' => now(),
            'deactivation_reason' => DeactivationReason::NOT_USING,
            'deactivated_by' => $deactivatedTutor->id,
        ])->save();

        Sanctum::actingAs($professional);

        $response = $this->getJson('/api/professional/clients?active_only=1')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($activeTutor->id));
        $this->assertFalse($ids->contains($deactivatedTutor->id));
    }

    /**
     * Monta o estado final de uma anonimização LGPD diretamente (`forceFill` + `delete()`) em
     * vez de chamar `POST /lgpd/delete-account` — esse endpoint tem um bug pré-existente,
     * não relacionado a esta tarefa e fora do escopo dela (`Add fillable property [deleted_at]
     * ...`, ver `LgpdDeleteAccountTest`), que hoje impede a chamada real de completar. O que
     * este teste precisa garantir é só o filtro da carteira do profissional, não o endpoint.
     */
    public function test_lgpd_anonymized_client_never_appears(): void
    {
        $professional = User::factory()->professional()->create();
        $tutor = $this->createLinkedTutor($professional);

        $tutor->forceFill(['name' => 'Usuario Removido'])->save();
        $tutor->delete();

        Sanctum::actingAs($professional);
        $response = $this->getJson('/api/professional/clients')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($tutor->id), 'Conta anonimizada por LGPD não pode aparecer na carteira do profissional.');
    }
}
