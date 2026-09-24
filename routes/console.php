<?php

use Illuminate\Support\Facades\Schedule;

// Health reminders - daily at 8:00 AM
Schedule::command('reminders:health')->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/health-reminders.log'));

/**
 * `notifications:send` nunca esteve agendado — achado na Fase 8 junto com a migration
 * ausente de `scheduled_notifications` (a tabela existir não adianta nada se o comando
 * nunca roda sozinho). Cadência derivada da janela mais apertada que o próprio comando
 * define (`SendScheduledNotifications::sendAppointmentReminders()`): o lembrete de 2h
 * dispara para `appointment_date` entre `now()+1h50` e `now()+2h10` — uma janela de
 * SÓ 20 minutos (a de 24h tem a mesma largura). Rodar a cada 10 minutos garante DUAS
 * execuções dentro de qualquer janela de 20 minutos em operação normal — margem de
 * segurança caso uma execução atrase ou seja pulada por `withoutOverlapping()`, sem
 * desperdício: a query de lembrete usa índice dedicado (ver migration
 * `2026_10_03_100063`) e a de fila usa o índice parcial `WHERE sent = false` de
 * `scheduled_notifications`, então rodar com a fila vazia é barato mesmo a cada 10min.
 * `sendQueuedNotifications()` não tem janela própria — 10 minutos também vira o atraso
 * máximo aceitável de entrega de uma notificação agendada avulsa (vacina, medicação),
 * bem dentro do que o produto pede.
 */
Schedule::command('notifications:send')->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/scheduled-notifications.log'));

/**
 * `crm:recalculate-client-profiles` — contrato
 * `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`, regra de negócio 4: reforço
 * agendado do cache lazy. A leitura sob demanda (`ensureFreshFor()`) já recalcula sozinha
 * quando o cache passa de 24h — isto só evita que o PRIMEIRO acesso do dia pague o custo.
 */
Schedule::command('crm:recalculate-client-profiles')->dailyAt('05:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/crm-recalculate-client-profiles.log'));

/**
 * `crm:run-automations` — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`,
 * item 4 do escopo. A cada 15 min: mais apertado que os outros comandos de CRM/lembrete
 * porque `post_appointment_followup`/`vaccine_due` são comparados por DIA (`whereDate`), não
 * por hora — rodar mais devagar só atrasaria quando no dia a mensagem sai, sem ganhar nada em
 * troca (nenhum gatilho tem janela mais estreita que 1 dia).
 */
Schedule::command('crm:run-automations')->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/crm-run-automations.log'));

/**
 * `geocoding:retry-failed` — endereços salvos sem coordenada (geocoding falhou no cadastro ou
 * na edição) ficam FORA da busca por proximidade até resolver. Uma vez por dia basta: a
 * própria fila já retenta cada falha por ~1 h (`GeocodeUserAddress::$backoff`); isto recolhe
 * o que a fila não cobre (provedor sem chave no momento da falha, tentativas esgotadas).
 */
Schedule::command('geocoding:retry-failed')->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/geocoding-retry.log'));
