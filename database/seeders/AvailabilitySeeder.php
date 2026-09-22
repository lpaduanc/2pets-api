<?php

namespace Database\Seeders;

use App\Models\Availability;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Popula `availabilities` para os profissionais já criados por `ProfessionalSeeder` e
 * `DemoDataSeeder` — sem isto, `GET /api/public/booking/availability` (`AvailabilityService`)
 * SEMPRE devolvia `[]` em ambiente de desenvolvimento: a tabela existia, mas nenhum seeder a
 * populava (Fase 1 do fluxo de agendamento).
 *
 * Idempotente por reconstrução: para cada e-mail, apaga a agenda anterior sem local
 * (`location_id IS NULL`) e recria a partir de `WEEKLY_SCHEDULES` — rodar de novo não
 * duplica nem acumula lixo.
 */
class AvailabilitySeeder extends Seeder
{
    /**
     * Um por e-mail já semeado por `ProfessionalSeeder`/`DemoDataSeeder`. `petshop@2pets.com`
     * usa slot de 60min e cobre sábado inteiro de propósito — a variedade que o contrato
     * desta fase pede, e coerente com banho e tosa levar mais tempo que uma consulta.
     *
     * @var array<string, list<array{day_of_week: int, start_time: string, end_time: string, slot_duration: int, buffer_time: int}>>
     */
    private const WEEKLY_SCHEDULES = [
        'vet@2pets.com' => [
            ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '18:00', 'slot_duration' => 30, 'buffer_time' => 0],
            ['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '18:00', 'slot_duration' => 30, 'buffer_time' => 0],
            ['day_of_week' => 3, 'start_time' => '08:00', 'end_time' => '18:00', 'slot_duration' => 30, 'buffer_time' => 0],
            ['day_of_week' => 4, 'start_time' => '08:00', 'end_time' => '18:00', 'slot_duration' => 30, 'buffer_time' => 0],
            ['day_of_week' => 5, 'start_time' => '08:00', 'end_time' => '18:00', 'slot_duration' => 30, 'buffer_time' => 0],
            ['day_of_week' => 6, 'start_time' => '08:00', 'end_time' => '12:00', 'slot_duration' => 30, 'buffer_time' => 0],
        ],
        'petshop@2pets.com' => [
            ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '19:00', 'slot_duration' => 60, 'buffer_time' => 15],
            ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '19:00', 'slot_duration' => 60, 'buffer_time' => 15],
            ['day_of_week' => 3, 'start_time' => '09:00', 'end_time' => '19:00', 'slot_duration' => 60, 'buffer_time' => 15],
            ['day_of_week' => 4, 'start_time' => '09:00', 'end_time' => '19:00', 'slot_duration' => 60, 'buffer_time' => 15],
            ['day_of_week' => 5, 'start_time' => '09:00', 'end_time' => '19:00', 'slot_duration' => 60, 'buffer_time' => 15],
            ['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '19:00', 'slot_duration' => 60, 'buffer_time' => 15],
        ],
        'dra.ana@2pets.com' => [
            ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '18:00', 'slot_duration' => 40, 'buffer_time' => 10],
            ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '18:00', 'slot_duration' => 40, 'buffer_time' => 10],
            ['day_of_week' => 3, 'start_time' => '09:00', 'end_time' => '18:00', 'slot_duration' => 40, 'buffer_time' => 10],
            ['day_of_week' => 4, 'start_time' => '09:00', 'end_time' => '18:00', 'slot_duration' => 40, 'buffer_time' => 10],
            ['day_of_week' => 5, 'start_time' => '09:00', 'end_time' => '18:00', 'slot_duration' => 40, 'buffer_time' => 10],
        ],
        'dr.marcos@2pets.com' => [
            ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '17:00', 'slot_duration' => 45, 'buffer_time' => 15],
            ['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '17:00', 'slot_duration' => 45, 'buffer_time' => 15],
            ['day_of_week' => 3, 'start_time' => '08:00', 'end_time' => '17:00', 'slot_duration' => 45, 'buffer_time' => 15],
            ['day_of_week' => 4, 'start_time' => '08:00', 'end_time' => '17:00', 'slot_duration' => 45, 'buffer_time' => 15],
            ['day_of_week' => 5, 'start_time' => '08:00', 'end_time' => '17:00', 'slot_duration' => 45, 'buffer_time' => 15],
        ],
        'vet@2pets.com.br' => [
            ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '18:00', 'slot_duration' => 30, 'buffer_time' => 0],
            ['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '18:00', 'slot_duration' => 30, 'buffer_time' => 0],
            ['day_of_week' => 3, 'start_time' => '08:00', 'end_time' => '18:00', 'slot_duration' => 30, 'buffer_time' => 0],
            ['day_of_week' => 4, 'start_time' => '08:00', 'end_time' => '18:00', 'slot_duration' => 30, 'buffer_time' => 0],
            ['day_of_week' => 5, 'start_time' => '08:00', 'end_time' => '18:00', 'slot_duration' => 30, 'buffer_time' => 0],
            ['day_of_week' => 6, 'start_time' => '08:00', 'end_time' => '12:00', 'slot_duration' => 30, 'buffer_time' => 0],
        ],
    ];

    public function run(): void
    {
        Collection::make(self::WEEKLY_SCHEDULES)->each(function (array $windows, string $email): void {
            $this->seedForEmail($email, $windows);
        });
    }

    /**
     * @param  list<array{day_of_week: int, start_time: string, end_time: string, slot_duration: int, buffer_time: int}>  $windows
     */
    private function seedForEmail(string $email, array $windows): void
    {
        $professional = User::where('email', $email)->first();

        if ($professional === null) {
            $this->command?->warn("AvailabilitySeeder: usuário {$email} não encontrado — rode ProfessionalSeeder/DemoDataSeeder antes.");

            return;
        }

        Availability::where('professional_id', $professional->id)->whereNull('location_id')->delete();

        foreach ($windows as $window) {
            Availability::create([
                ...$window,
                'professional_id' => $professional->id,
                'organization_id' => $professional->activeOrganizationId(),
                'location_id' => null,
                'is_active' => true,
            ]);
        }
    }
}
