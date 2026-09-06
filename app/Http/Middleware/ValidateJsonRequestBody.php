<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejeita corpo JSON malformado antes que qualquer Form Request rode.
 *
 * Sem isso, `json_decode` falha silenciosamente dentro do próprio Laravel:
 * `Request::json()` faz `(array) json_decode(...)`, então um body inválido
 * vira `[]` e um body JSON válido mas escalar/array vira um array numérico
 * inútil como fonte de input. Como os Form Requests deste projeto usam muito
 * `sometimes` (patch parcial legítimo), o resultado é uma request "vazia":
 * nada é validado, nada é escrito, e o endpoint responde 200 como se tivesse
 * tido sucesso — perda silenciosa de dado.
 *
 * Só age quando o método HTTP admite corpo (`POST`/`PUT`/`PATCH`/`DELETE`),
 * HÁ corpo e o `Content-Type` é JSON. `GET`/`HEAD` nunca são avaliados — a
 * própria infraestrutura de teste do Laravel (`getJson()`) sempre manda
 * `Content-Type: application/json` com `[]` como corpo em requisições GET
 * sem payload, o que tornaria qualquer `GET` num falso positivo de "corpo
 * não é objeto" sem nenhum ganho real (`GET` não tem corpo processado por
 * este projeto). E requisições legitimamente sem corpo continuam passando.
 */
class ValidateJsonRequestBody
{
    private const INVALID_JSON_MESSAGE = 'O corpo da requisição não é um JSON válido.';

    private const NOT_AN_OBJECT_MESSAGE = 'O corpo da requisição precisa ser um objeto JSON.';

    /** @var list<string> */
    private const METHODS_WITH_BODY = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->hasJsonBody($request)) {
            return $next($request);
        }

        return $this->validateJsonBody($request) ?? $next($request);
    }

    private function hasJsonBody(Request $request): bool
    {
        return in_array($request->getMethod(), self::METHODS_WITH_BODY, true)
            && $request->isJson()
            && trim($request->getContent()) !== '';
    }

    private function validateJsonBody(Request $request): ?Response
    {
        $decoded = json_decode($request->getContent());

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->badRequest(self::INVALID_JSON_MESSAGE);
        }

        if ($this->isAcceptableTopLevelValue($decoded)) {
            return null;
        }

        return $this->badRequest(self::NOT_AN_OBJECT_MESSAGE);
    }

    /**
     * Um JSON escalar (`"texto"`, `42`), `null` ou um array não-vazio
     * (`[1,2]`) é sintaticamente válido, mas não tem chave nenhuma para virar
     * input de formulário — o mesmo bug de "request vazia" se repetiria por
     * outro caminho, só que com dado de verdade perdido. `{...}` (inclusive
     * `{}`) é sempre aceito. `[]` também é aceito de propósito: é como o
     * próprio cliente HTTP deste projeto (`postJson($uri)` sem payload, nos
     * endpoints de ação como accept/revoke/cancel) representa "nenhum dado a
     * enviar" — equivalente a corpo vazio, nada a perder.
     */
    private function isAcceptableTopLevelValue(mixed $decoded): bool
    {
        if ($decoded instanceof \stdClass) {
            return true;
        }

        return is_array($decoded) && $decoded === [];
    }

    private function badRequest(string $message): Response
    {
        return response()->json(['message' => $message], Response::HTTP_BAD_REQUEST);
    }
}
