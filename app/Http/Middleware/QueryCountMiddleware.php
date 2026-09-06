<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adiciona `X-Query-Count` e `X-Query-Time-Ms` na resposta em ambiente local.
 *
 * Instrumento de medição principal do plano de otimização (Fase 0): sem ele não há como
 * comparar "antes" e "depois" de um endpoint sem abrir o `perf.log` na mão. Guardado por
 * ambiente para custo zero fora de `local` — nem o `DB::listen` é registrado.
 */
class QueryCountMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local')) {
            return $next($request);
        }

        $queryCount = 0;
        $queryTimeMs = 0.0;

        DB::listen(function (QueryExecuted $event) use (&$queryCount, &$queryTimeMs): void {
            $queryCount++;
            $queryTimeMs += $event->time;
        });

        $response = $next($request);

        $response->headers->set('X-Query-Count', (string) $queryCount);
        $response->headers->set('X-Query-Time-Ms', (string) round($queryTimeMs, 2));

        return $response;
    }
}
