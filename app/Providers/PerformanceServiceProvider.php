<?php

namespace App\Providers;

use Carbon\CarbonInterval;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Instrumentação de performance (Fase 0 do plano de otimização).
 *
 * Fica separado do AppServiceProvider de propósito: isto é instrumentação de medição,
 * não listener de domínio. Tudo aqui é log, não bloqueio — o modo throw do lazy loading
 * só entra na Fase 7, depois que os índices e o N+1 sistemático estiverem resolvidos.
 */
class PerformanceServiceProvider extends ServiceProvider
{
    private const SLOW_QUERY_THRESHOLD_SECONDS = 1;

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureLazyLoadingGuard();
        $this->configureAttributeGuard();
        $this->configureSlowQueryLogging();
    }

    private function configureLazyLoadingGuard(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            Log::channel('perf')->warning('Lazy loading detectado (N+1 em potencial)', [
                'model' => $model::class,
                'relation' => $relation,
                'model_id' => $model->getKey(),
                'origin' => $this->firstCallerOutsideVendor(),
            ]);
        });
    }

    private function configureAttributeGuard(): void
    {
        // preventAccessingMissingAttributes NÃO é ligado de propósito: o
        // ProfessionalSearchService monta a busca geográfica com
        // selectRaw('users.*, ... AS distance_km') e addSelect condicionais — acessar um
        // atributo que não veio de um select específico é o caminho normal ali, então essa
        // trava geraria falso positivo constante no core do produto.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }

    private function configureSlowQueryLogging(): void
    {
        if ($this->app->isProduction()) {
            return;
        }

        DB::whenQueryingForLongerThan(
            CarbonInterval::seconds(self::SLOW_QUERY_THRESHOLD_SECONDS),
            function (Connection $connection, QueryExecuted $event): void {
                Log::channel('perf')->warning('Tempo acumulado de query acima do limite', [
                    'connection' => $connection->getName(),
                    'total_duration_ms' => $connection->totalQueryDuration(),
                    'last_query_sql' => $event->sql,
                    'last_query_time_ms' => $event->time,
                ]);
            }
        );
    }

    /**
     * Melhor esforço para achar onde, fora do vendor/, o lazy load foi disparado —
     * ajuda a localizar o N+1 sem precisar de xdebug.
     */
    private function firstCallerOutsideVendor(): ?string
    {
        $frame = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25))
            ->first(fn (array $frame): bool => isset($frame['file'])
                && $frame['file'] !== __FILE__
                && ! str_contains($frame['file'], DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR));

        return $frame === null ? null : $frame['file'].':'.($frame['line'] ?? '?');
    }
}
