<?php

namespace App\Http\Middleware;

use App\Enums\AppLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aplica o idioma pedido em `Accept-Language` ao locale da aplicação, só para a resposta
 * desta requisição (não persiste nada). Usado hoje só na rota do schema de cadastro
 * (`GET /register/professional-schema`) — ver alcance documentado em `AppLocale`.
 */
class SetLocaleFromAcceptLanguage
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = AppLocale::fromAcceptLanguageHeader($request->header('Accept-Language'));

        App::setLocale($locale->value);

        return $next($request);
    }
}
