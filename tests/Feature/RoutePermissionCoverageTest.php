<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Item 22 do backlog gap-simplesvet — critério de aceite: "Teste automatizado falha se
 * alguma rota autenticada de `routes/api.php` não tiver permissão declarada no mapa."
 *
 * O "mapa" aqui não é "toda rota tem que usar `permission:...`" — a maior parte do sistema
 * (48 controllers legados) já autoriza por Policy/FormRequest/ownership própria, e reescrever
 * isso não é o objetivo deste teste (ver spec 22 §Fora de escopo). O mapa é, em vez disso,
 * `tests/Fixtures/route-permission-allowlist.php`: toda rota autenticada SEM `permission:...`
 * precisa estar documentada ali. Rota autenticada nova que não declarar permissão E não for
 * adicionada à allowlist FALHA aqui — esse é o gate contra regressão que o critério pede,
 * sem exigir migração retroativa de 490 rotas legadas de uma vez.
 *
 * Não falha por entrada da allowlist que deixou de existir (rota removida/renomeada por outro
 * agente) — só por rota NOVA fora do mapa, para não quebrar trabalho paralelo em andamento.
 */
class RoutePermissionCoverageTest extends TestCase
{
    public function test_every_authenticated_route_declares_permission_or_is_in_the_allowlist(): void
    {
        $allowlist = $this->loadAllowlist();
        $uncovered = $this->authenticatedRoutesWithoutPermission()
            ->reject(fn (string $signature): bool => in_array($signature, $allowlist, true));

        $this->assertTrue($uncovered->isEmpty(), $this->failureMessage($uncovered));
    }

    /**
     * @return list<string>
     */
    private function loadAllowlist(): array
    {
        return require base_path('tests/Fixtures/route-permission-allowlist.php');
    }

    /**
     * @return Collection<int, string>
     */
    private function authenticatedRoutesWithoutPermission(): Collection
    {
        return collect(RouteFacade::getRoutes())
            ->filter(fn (Route $route): bool => $this->requiresAuthentication($route))
            ->reject(fn (Route $route): bool => $this->declaresPermission($route))
            ->flatMap(fn (Route $route): array => $this->signaturesFor($route))
            ->unique()
            ->values();
    }

    private function requiresAuthentication(Route $route): bool
    {
        return collect($route->gatherMiddleware())
            ->contains(fn (string $middleware): bool => str_starts_with($middleware, 'auth:sanctum'));
    }

    private function declaresPermission(Route $route): bool
    {
        return collect($route->gatherMiddleware())
            ->contains(fn (string $middleware): bool => str_starts_with($middleware, 'permission:'));
    }

    /**
     * @return list<string>
     */
    private function signaturesFor(Route $route): array
    {
        return collect($route->methods())
            ->reject(fn (string $method): bool => $method === 'HEAD')
            ->map(fn (string $method): string => "{$method} {$route->uri()}")
            ->all();
    }

    /**
     * @param  Collection<int, string>  $uncovered
     */
    private function failureMessage(Collection $uncovered): string
    {
        return "Rota(s) autenticada(s) sem `permission:...` e fora da allowlist:\n"
            .$uncovered->map(fn (string $signature): string => "  - {$signature}")->implode("\n")
            ."\n\nAdicione `->middleware('permission:...')` à rota OU documente-a em "
            .'tests/Fixtures/route-permission-allowlist.php explicando por que a autorização '
            .'já é feita por Policy/FormRequest/ownership própria.';
    }
}
