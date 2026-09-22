<?php

namespace App\Observers\ReferenceData;

use App\Services\ReferenceData\ReferenceDataCacheService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Invalida o cache de dado de referencia (Fase 9) por contador de versao — nunca
 * `KEYS`/`SCAN`. Registrado em `AppServiceProvider::boot()` para `Pathology`,
 * `ImmunizationProduct` (substitui `VaccineCatalog`, legado — ver contrato
 * `docs/gap-simplesvet/contratos/13-contrato-api.md`), `FoodBrand`, `Specialty`,
 * `FoodAllergy`, `DietaryRestriction` e `Breed`. Generico: usa `$model->getTable()` para
 * incrementar so o contador da tabela que mudou, nunca o de outra tabela de referencia.
 */
final class ReferenceDataCacheObserver
{
    public function __construct(
        private readonly ReferenceDataCacheService $referenceDataCacheService,
    ) {}

    public function saved(Model $model): void
    {
        $this->bumpVersion($model);
    }

    public function deleted(Model $model): void
    {
        $this->bumpVersion($model);
    }

    public function restored(Model $model): void
    {
        $this->bumpVersion($model);
    }

    private function bumpVersion(Model $model): void
    {
        Cache::increment($this->referenceDataCacheService->versionKey($model->getTable()));
    }
}
