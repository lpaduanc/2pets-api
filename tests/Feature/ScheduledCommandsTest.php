<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

/**
 * `notifications:send` nunca esteve registrado em `routes/console.php` — a tabela e o
 * comando funcionavam, mas nada disparava sozinho (achado na Fase 8, corrigido nesta
 * rodada). Este teste trava a cadência: se alguém remover a linha do agendamento por
 * engano (ex.: limpando `routes/console.php`), o teste quebra em vez de o silêncio se
 * repetir sem ninguém notar.
 */
class ScheduledCommandsTest extends TestCase
{
    public function test_notifications_send_is_scheduled_every_ten_minutes(): void
    {
        $this->app->make(ConsoleKernel::class)->bootstrap();

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($scheduledEvent): bool => str_contains($scheduledEvent->command, 'notifications:send'));

        $this->assertNotNull($event, 'notifications:send precisa estar registrado no schedule (routes/console.php).');
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping, 'notifications:send precisa de withoutOverlapping — execuções sobrepostas duplicariam notificações.');
    }

    public function test_reminders_health_stays_scheduled_daily(): void
    {
        $this->app->make(ConsoleKernel::class)->bootstrap();

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($scheduledEvent): bool => str_contains($scheduledEvent->command, 'reminders:health'));

        $this->assertNotNull($event, 'reminders:health não deveria ter sido removido do schedule.');
        $this->assertSame('0 8 * * *', $event->expression);
    }
}
