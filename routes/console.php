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

/**
 * Carteira do tutor — lança os gastos recorrentes vencidos. 06:00 UTC = 03:00 em São Paulo:
 * quando o tutor abre o app de manhã, a ração do mês já está lá. O comando é idempotente
 * (índice único por recorrência + dia), então uma execução repetida não duplica nada.
 */
Schedule::command('wallet:generate-recurring')->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/wallet-recurring.log'));

/**
 * Pacotes vendidos vencidos com saldo (PLANO 2.7): crédito na conta do cliente (padrão) ou
 * receita de expiração, pela política da organização. 03:10 UTC = 00:10 em São Paulo: o pacote
 * que venceu ontem é resolvido logo na virada. Idempotente (`expiration_resolved_at`).
 */
Schedule::command('sold-packages:resolve-expired')->dailyAt('03:10')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/sold-packages-expired.log'));

/**
 * Foto diária do estoque valorizado (PLANO 3.6) às 23:55 de São Paulo — o widget "Custo
 * estocado / Venda estocada" compara com a foto do último dia do mês anterior. Idempotente por
 * dia (rodar de novo regrava a mesma foto).
 */
Schedule::command('stock:snapshot-valuation')->dailyAt('23:55')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/stock-valuation-snapshot.log'));

/**
 * Séries de agendamento (PLANO 3.1): estende a janela materializada até hoje + 90 dias.
 * 02:00 de São Paulo; idempotente (não duplica ocorrência).
 */
Schedule::command('appointments:extend-series')->dailyAt('02:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/appointment-series.log'));
