<?php

namespace App\Providers;

use App\Events\ReviewCreated;
use App\Listeners\SendAppointmentNotification;
use App\Listeners\SendReviewNotification;
use App\Models\Breed;
use App\Models\DietaryRestriction;
use App\Models\FoodAllergy;
use App\Models\FoodBrand;
use App\Models\Pathology;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Specialty;
use App\Models\User;
use App\Models\VaccineCatalog;
use App\Observers\ProfessionalSpecialtyObserver;
use App\Observers\ReferenceData\ReferenceDataCacheObserver;
use App\Observers\Search\ProfessionalSearchCacheObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Modelos de dado de referencia com cache de 24h (Fase 9 do plano de
     * otimizacao) — cada um ganha seu proprio contador de versao via
     * `ReferenceDataCacheObserver`, indexado por `getTable()`.
     *
     * @var list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private const REFERENCE_DATA_MODELS = [
        Pathology::class,
        VaccineCatalog::class,
        FoodBrand::class,
        Specialty::class,
        FoodAllergy::class,
        DietaryRestriction::class,
        Breed::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::subscribe(SendAppointmentNotification::class);
        Event::listen(ReviewCreated::class, SendReviewNotification::class);

        $this->registerSearchCacheObservers();
        $this->registerReferenceDataCacheObservers();
        $this->registerRateLimiters();
    }

    /**
     * Limite da busca pública, por IP.
     *
     * 60/min, e não os 30/min que a rota usava. O motivo é o comportamento real da tela: a
     * busca do app é incremental (o usuário digita o termo e mexe em três ou quatro filtros
     * seguidos) e uma sessão legítima estourava 30 requisições em um minuto — nenhum debounce
     * razoável resolve isso sem matar a sensação de busca ao vivo.
     *
     * Por que não mais que 60: throttle por minuto NÃO é o que protege contra raspagem. São
     * ~2.250 páginas de resultado com `per_page=12`; a 30/min uma raspagem leva 75 minutos, a
     * 60/min leva 37 — nenhum dos dois é barreira para quem tem paciência, então subir o
     * número não "abre" nada que já não estivesse aberto. O que limita de verdade é custo de
     * servidor: uma busca com termo custa de 0,5 s a 4 s de Postgres, e 60 dessas por minuto
     * vindas de um único IP já é carga real. O cache de resultado (60 s, por célula
     * geográfica) absorve a repetição, mas não termos distintos.
     *
     * Fica registrado para o security-specialist: este endpoint não tem NENHUM controle
     * antirraspagem além deste throttle. Se proteger o catálogo virar requisito, a resposta é
     * outro mecanismo (exigir conta para paginação profunda, por exemplo), não um número
     * menor aqui.
     *
     * ── `reverse-geocode`: 10/min por IP ──────────────────────────────────────────────
     * Seis vezes mais apertado que a busca, e por um motivo diferente: cada chamada pode
     * virar uma requisição PAGA ao Google Maps Platform. Aqui o throttle não protege
     * servidor, protege fatura.
     *
     * 10/min é generoso para o uso real e mesquinho para abuso. O uso real é UMA chamada por
     * sessão: o app pede a localização, mostra a modal de confirmação e pronto. Os outros 9
     * cobrem o usuário que troca de posição no mapa, recarrega a página ou tem duas abas
     * abertas — folga suficiente para ninguém legítimo ver 429.
     *
     * Do outro lado, 10/min impede que a rota vire um proxy gratuito do Geocoding API:
     * varrer uma cidade em grade de 100 m exigiria dezenas de milhares de pontos, o que a
     * 10/min leva dias por IP. O cache de 30 dias por coordenada (limite dos Termos do
     * Google) absorve a repetição legítima sem gastar cota nenhuma.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('public-search', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('reverse-geocode', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
    }

    /**
     * Cache do resultado de busca de profissionais (Fase 9): invalidado por
     * contador de versao quando qualquer um destes tres models muda.
     */
    private function registerSearchCacheObservers(): void
    {
        User::observe(ProfessionalSearchCacheObserver::class);
        Professional::observe(ProfessionalSearchCacheObserver::class);
        Professional::observe(ProfessionalSpecialtyObserver::class);
        Service::observe(ProfessionalSearchCacheObserver::class);
    }

    private function registerReferenceDataCacheObservers(): void
    {
        foreach (self::REFERENCE_DATA_MODELS as $referenceDataModel) {
            $referenceDataModel::observe(ReferenceDataCacheObserver::class);
        }
    }
}
