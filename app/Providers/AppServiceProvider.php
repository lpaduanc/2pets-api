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
use App\Observers\ReferenceData\ReferenceDataCacheObserver;
use App\Observers\Search\ProfessionalSearchCacheObserver;
use Illuminate\Support\Facades\Event;
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
    }

    /**
     * Cache do resultado de busca de profissionais (Fase 9): invalidado por
     * contador de versao quando qualquer um destes tres models muda.
     */
    private function registerSearchCacheObservers(): void
    {
        User::observe(ProfessionalSearchCacheObserver::class);
        Professional::observe(ProfessionalSearchCacheObserver::class);
        Service::observe(ProfessionalSearchCacheObserver::class);
    }

    private function registerReferenceDataCacheObservers(): void
    {
        foreach (self::REFERENCE_DATA_MODELS as $referenceDataModel) {
            $referenceDataModel::observe(ReferenceDataCacheObserver::class);
        }
    }
}
