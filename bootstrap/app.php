<?php

use App\Exceptions\DuplicateRegistrationException;
use App\Http\Middleware\CheckFeature;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\QueryCountMiddleware;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocaleFromAcceptLanguage;
use App\Http\Middleware\ValidateJsonRequestBody;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Achado na Fase 4 (notificações): sem isto, o auto-discovery de eventos do Laravel
    // (ativo por padrão, mesmo sem `EventServiceProvider` no projeto) registra TODO
    // listener de `app/Listeners` cujo método público tem um Event tipado no primeiro
    // parâmetro — inclusive os que já são registrados EXPLICITAMENTE em
    // `AppServiceProvider::boot()` (`Event::subscribe`/`Event::listen`). Resultado medido
    // com `php artisan event:list`: `SendAppointmentNotification`, `SendReviewNotification`,
    // `SendReviewInviteNotification` e `DispatchFallbackPushNotification` apareciam
    // DUAS VEZES cada — toda notificação de agendamento, avaliação e push (Fase 4) estava
    // sendo enviada em dobro para o usuário, silenciosamente, desde antes desta fase.
    // Este projeto é 100% explícito em todo outro lugar (nenhum outro evento depende de
    // convenção); `discover: false` alinha o registro de evento com essa mesma prática.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        // SecurityHeaders only needed for web responses, not API
        $middleware->appendToGroup('web', SecurityHeaders::class);

        // X-Query-Count / X-Query-Time-Ms — instrumentação de performance (Fase 0),
        // passthrough fora do ambiente local (ver QueryCountMiddleware). Prepend para
        // contar também as queries do SubstituteBindings (route model binding).
        $middleware->prependToGroup('api', QueryCountMiddleware::class);

        // Corpo JSON malformado (ou JSON válido mas não-objeto) tem que virar 400 antes
        // de qualquer Form Request rodar — ver ValidateJsonRequestBody. Roda também nos
        // webhooks (`routes/api.php` está todo sob o grupo `api`), mas Stripe e Mercado
        // Pago sempre mandam um objeto JSON como corpo, então não são afetados; sem corpo
        // ou Content-Type não-JSON o middleware é passthrough.
        $middleware->appendToGroup('api', ValidateJsonRequestBody::class);

        // Feature flags — guard V2/V3 routes
        $middleware->alias([
            'feature' => CheckFeature::class,
            // Aplicado só na rota do schema de cadastro (`register/professional-schema`)
            // por enquanto — não é um switch global de locale da API. Ver `AppLocale`.
            'locale' => SetLocaleFromAcceptLanguage::class,
            // Item 22 — gate de rota por permissão Spatie granular. Ver `EnsurePermission`.
            'permission' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Colisão de documento (cpf/cnpj/crmv/e-mail) já sinalizada explicitamente por
        // código de aplicação (ver `RegistrationDraftController`) — a exceção já sabe
        // renderizar o próprio contrato.
        $exceptions->render(fn (DuplicateRegistrationException $e): JsonResponse => $e->render());

        // Colisão que só o índice único do banco pegou: sobrevive mesmo com `Rule::unique`
        // no Form Request quando duas requisições concorrentes passam na validação ao mesmo
        // tempo (race condition). Sem isto, `QueryException` (SQLSTATE 23505) vazava como
        // 500 cru. Retornar sem valor (constraint não mapeada) devolve o comportamento
        // padrão do framework para qualquer outra `QueryException` (FK, CHECK, NOT NULL).
        $exceptions->render(function (QueryException $e): ?JsonResponse {
            return DuplicateRegistrationException::fromQueryException($e)?->render();
        });
    })->create();
