<?php

namespace Tests\Feature;

use App\Models\Availability;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use App\Notifications\InAppNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * "Corrigir o fluxo de agendamento de serviço a partir do login do tutor" — a jornada
 * INTEIRA, ligada, batendo em endpoint HTTP de verdade em cada passo (não chamando Service
 * por dentro). Cada fase (1 a 7) verificou sua própria parte isoladamente; este é o único
 * teste que prova que elas se encaixam, na ordem que um tutor real percorre:
 *
 *   login → busca por especialidade da equipe (Fase 7) → perfil da clínica com
 *   `team_services` (Fase 2) → equipe filtrada por serviço (Fase 2) → dias/horários
 *   disponíveis (Fase 1/2) → cria o agendamento (`pending`) → profissional notificado
 *   EXATAMENTE uma vez (a duplicação da Fase 4 não pode voltar) → profissional confirma
 *   (Fase 4) → tutor notificado → o horário reservado desaparece da agenda pública.
 *
 * Três variações: sinal desligado (padrão, Fase 6), sinal ligado, e modo "qualquer
 * profissional disponível" (Fase 2) — provando que este último sempre grava um
 * `professional_id` concreto, nunca `null`.
 */
class AppointmentJourneyEndToEndTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tutor: User, pet: Pet, owner: User, organization: Organization, cardiologist: User, service: Service, targetDate: Carbon}
     */
    private function setUpClinicScenario(): array
    {
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id, 'name' => 'Rex']);

        $owner = User::factory()->professional()->create(['user_type' => 'clinic']);
        Professional::factory()->create(['user_id' => $owner->id, 'professional_type' => 'clinic', 'specialties' => []]);

        $organization = Organization::factory()->create();
        OrganizationMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
        ]);

        $cardiologist = User::factory()->professional()->create(['name' => 'Dra. Cardiologista da Equipe']);
        Professional::factory()->create(['user_id' => $cardiologist->id, 'specialties' => ['cardiologia']]);
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $cardiologist->id,
        ]);

        $service = Service::create([
            'professional_id' => $cardiologist->id,
            'organization_id' => $organization->id,
            'name' => 'Consulta Cardiológica',
            'category' => 'consultation',
            'duration' => 40,
            'price' => 250.00,
            'active' => true,
        ]);

        $targetDate = now()->addDay();

        Availability::create([
            'professional_id' => $cardiologist->id,
            'organization_id' => $organization->id,
            'day_of_week' => $targetDate->dayOfWeek,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'slot_duration' => 40,
            'buffer_time' => 0,
            'is_active' => true,
        ]);

        return compact('tutor', 'pet', 'owner', 'organization', 'cardiologist', 'service', 'targetDate');
    }

    /**
     * `Auth::forgetGuards()` — achado escrevendo este teste: dentro do MESMO método de
     * teste, o container/`AuthManager` é reaproveitado entre chamadas HTTP simuladas
     * (ao contrário de produção, onde cada request é um processo php-fpm novo). O guard
     * do Sanctum (`RequestGuard`) memoiza o usuário resolvido na PRIMEIRA vez que
     * `->user()` é chamado (`GuardHelpers::$user`) e nunca reconsulta o token depois —
     * então, sem isto, autenticar um SEGUNDO usuário aqui (ex.: tutor → profissional)
     * continuaria resolvendo o PRIMEIRO em toda chamada seguinte, mesmo com um Bearer
     * token novo. Não é bug de produção; é uma armadilha exclusiva de harness de teste
     * que simula múltiplos atores no mesmo processo.
     */
    private function loginAs(User $user): string
    {
        Auth::forgetGuards();

        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password']);
        $response->assertOk();

        Auth::forgetGuards();

        return $response->json('access_token');
    }

    public function test_full_tutor_journey_with_deposit_disabled_by_default(): void
    {
        Notification::fake();

        [
            'tutor' => $tutor, 'pet' => $pet, 'owner' => $owner, 'organization' => $organization,
            'cardiologist' => $cardiologist, 'service' => $service, 'targetDate' => $targetDate,
        ] = $this->setUpClinicScenario();

        // 1) Tutor autentica pelo endpoint de verdade.
        $this->withToken($this->loginAs($tutor));

        // 2) Busca por especialidade encontra a clínica pela especialidade da EQUIPE (Fase 7).
        $search = $this->getJson('/api/public/search?'.http_build_query(['specialty' => ['cardiologia']]));
        $search->assertOk();
        $this->assertContains($owner->id, collect($search->json('data'))->pluck('id'));

        // 3) Perfil da clínica: `has_team` + `team_services` agrupado (Fase 2).
        $profile = $this->getJson("/api/public/professionals/{$owner->id}");
        $profile->assertOk()->assertJsonPath('data.has_team', true);

        $catalogEntry = collect($profile->json('team_services'))->firstWhere('name', 'Consulta Cardiológica');
        $this->assertNotNull($catalogEntry, 'O serviço da equipe não apareceu no catálogo agregado do perfil.');
        $serviceId = $catalogEntry['service_ids'][0];
        $this->assertSame($service->id, $serviceId);

        // 4) Equipe filtrada pelo serviço escolhido.
        $team = $this->getJson("/api/public/professionals/{$owner->id}/team?service_id={$serviceId}");
        $team->assertOk();
        $this->assertContains($cardiologist->id, collect($team->json('data'))->pluck('id'));

        // 5) Dias disponíveis do mês + agenda do profissional escolhido.
        $days = $this->getJson('/api/public/booking/availability-days?'.http_build_query([
            'professional_id' => $cardiologist->id,
            'month' => $targetDate->format('Y-m'),
        ]));
        $days->assertOk();
        $this->assertContains($targetDate->toDateString(), $days->json('data'));

        $slots = $this->getJson('/api/public/booking/availability?'.http_build_query([
            'professional_id' => $cardiologist->id,
            'organization_id' => $organization->id,
            'date' => $targetDate->toDateString(),
        ]));
        $slots->assertOk();
        $this->assertNotEmpty($slots->json('data'));
        $chosenStart = $slots->json('data.0.start_time');

        // 6) Cria o agendamento — nasce `pending`, exige confirmação.
        $booking = $this->postJson('/api/public/booking', [
            'professional_id' => $cardiologist->id,
            'organization_id' => $organization->id,
            'service_id' => $serviceId,
            'pet_id' => $pet->id,
            'appointment_date' => Carbon::parse($chosenStart)->toDateTimeString(),
        ]);
        $booking->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requires_confirmation', true);
        $appointmentId = $booking->json('data.id');

        // 7) Profissional notificado — com serviço, pet, tutor e horário — EXATAMENTE uma vez.
        Notification::assertSentToTimes($cardiologist, InAppNotification::class, 1);
        Notification::assertSentTo(
            $cardiologist,
            InAppNotification::class,
            function (InAppNotification $notification) use ($cardiologist, $tutor, $pet, $service): bool {
                $array = $notification->toArray($cardiologist);

                return $array['type'] === 'appointment_requested'
                    && str_contains($array['message'], $service->name)
                    && str_contains($array['message'], $pet->name)
                    && str_contains($array['message'], $tutor->name);
            }
        );

        // 8) Profissional confirma pelo endpoint dele.
        $this->withToken($this->loginAs($cardiologist));
        $confirmation = $this->postJson("/api/professional/appointments/{$appointmentId}/confirm");
        $confirmation->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.deposit_status', 'none')
            ->assertJsonPath('data.deposit_amount', null);

        // 9) Tutor recebe a notificação de confirmação — e só essa (sinal desligado).
        Notification::assertSentToTimes($tutor, InAppNotification::class, 1);
        Notification::assertSentTo(
            $tutor,
            InAppNotification::class,
            fn (InAppNotification $notification): bool => $notification->toArray($tutor)['type'] === 'appointment_confirmed'
        );

        // 10) O horário reservado some da disponibilidade pública.
        $slotsAfter = $this->getJson('/api/public/booking/availability?'.http_build_query([
            'professional_id' => $cardiologist->id,
            'organization_id' => $organization->id,
            'date' => $targetDate->toDateString(),
        ]));
        $slotsAfter->assertOk();
        $this->assertNotContains($chosenStart, collect($slotsAfter->json('data'))->pluck('start_time'));
    }

    public function test_full_tutor_journey_with_deposit_enabled(): void
    {
        Notification::fake();
        Http::fake(['api.mercadopago.com/*' => Http::response(['id' => 'mp-e2e-test', 'status' => 'pending'], 201)]);

        [
            'tutor' => $tutor, 'pet' => $pet, 'owner' => $owner, 'organization' => $organization,
            'cardiologist' => $cardiologist, 'service' => $service, 'targetDate' => $targetDate,
        ] = $this->setUpClinicScenario();

        // Pré-condição desta variação: o DONO liga o sinal pelo endpoint de configuração
        // (Fase 6), 20% do preço do serviço.
        $this->withToken($this->loginAs($owner));
        $this->putJson('/api/professional/deposit-settings', [
            'deposit_enabled' => true,
            'deposit_percentage' => 20,
        ])->assertOk();

        // 1-5) Mesma jornada do tutor até a escolha do horário.
        $this->withToken($this->loginAs($tutor));

        $profile = $this->getJson("/api/public/professionals/{$owner->id}");
        $serviceId = collect($profile->json('team_services'))->firstWhere('name', 'Consulta Cardiológica')['service_ids'][0];

        $slots = $this->getJson('/api/public/booking/availability?'.http_build_query([
            'professional_id' => $cardiologist->id,
            'organization_id' => $organization->id,
            'date' => $targetDate->toDateString(),
        ]));
        $chosenStart = $slots->json('data.0.start_time');

        // 6) Cria o agendamento — ainda sem cobrança nenhuma (sinal só é cobrado na
        // confirmação, decisão #1 do dono do produto).
        $booking = $this->postJson('/api/public/booking', [
            'professional_id' => $cardiologist->id,
            'organization_id' => $organization->id,
            'service_id' => $serviceId,
            'pet_id' => $pet->id,
            'appointment_date' => Carbon::parse($chosenStart)->toDateTimeString(),
        ]);
        $booking->assertCreated()->assertJsonPath('data.status', 'pending');
        $appointmentId = $booking->json('data.id');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointmentId,
            'deposit_status' => 'none',
            'deposit_amount' => null,
        ]);

        // 8) Profissional confirma — É AQUI que o sinal é calculado e cobrado.
        $this->withToken($this->loginAs($cardiologist));
        $confirmation = $this->postJson("/api/professional/appointments/{$appointmentId}/confirm");
        $confirmation->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertEquals(50.0, $confirmation->json('data.deposit_amount')); // 20% de 250.00
        $this->assertSame('pending', $confirmation->json('data.deposit_status'));

        // 9) Tutor recebe DUAS notificações: a cobrança do sinal (com o valor) e a
        // confirmação da consulta — nenhuma a mais, nenhuma a menos.
        Notification::assertSentToTimes($tutor, InAppNotification::class, 2);
        Notification::assertSentTo(
            $tutor,
            InAppNotification::class,
            function (InAppNotification $notification) use ($tutor): bool {
                $array = $notification->toArray($tutor);

                return $array['type'] === 'appointment_deposit_requested'
                    && str_contains($array['message'], '50,00');
            }
        );
    }

    public function test_any_available_professional_mode_always_persists_a_concrete_professional(): void
    {
        Notification::fake();

        [
            'tutor' => $tutor, 'pet' => $pet, 'organization' => $organization,
            'cardiologist' => $cardiologist, 'service' => $service, 'targetDate' => $targetDate,
        ] = $this->setUpClinicScenario();

        $this->withToken($this->loginAs($tutor));

        // Modo agregado: SEM professional_id, só o estabelecimento.
        $slots = $this->getJson('/api/public/booking/availability?'.http_build_query([
            'organization_id' => $organization->id,
            'date' => $targetDate->toDateString(),
        ]));
        $slots->assertOk();
        $this->assertNotEmpty($slots->json('data'));
        $this->assertArrayHasKey('professional_id', $slots->json('data.0'));
        $chosenStart = $slots->json('data.0.start_time');

        $booking = $this->postJson('/api/public/booking', [
            'organization_id' => $organization->id,
            'service_id' => $service->id,
            'pet_id' => $pet->id,
            'appointment_date' => Carbon::parse($chosenStart)->toDateTimeString(),
        ]);

        $booking->assertCreated();
        $professionalId = $booking->json('data.professional_id');

        $this->assertIsInt($professionalId);
        $this->assertSame($cardiologist->id, $professionalId);
        $this->assertDatabaseHas('appointments', [
            'id' => $booking->json('data.id'),
            'professional_id' => $cardiologist->id,
        ]);
    }
}
