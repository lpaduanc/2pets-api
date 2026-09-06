<?php

namespace App\Observers\Search;

use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use App\Services\Search\ProfessionalSearchCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Invalida o cache de busca de profissionais (Fase 9) por contador de versao — nunca
 * `KEYS`/`SCAN`, nunca `Cache::tags()`. Registrado em `AppServiceProvider::boot()`
 * para `User`, `Professional` e `Service`. Um unico `Cache::increment` torna toda
 * chave `professional_search:v{N}:*` anterior inalcancavel, sem varrer o Redis.
 */
final class ProfessionalSearchCacheObserver
{
    /**
     * Colunas de `users` que participam do WHERE/SELECT da busca
     * (`ProfessionalSearchService::buildBaseQuery()`/`applyDistanceSelect()`). Mudar
     * qualquer OUTRO campo do usuario (senha, telefone...) nao precisa invalidar a
     * busca inteira so porque 200k usuarios escrevem na mesma tabela — a maioria
     * tutores, que nem aparecem no resultado.
     */
    private const RELEVANT_USER_ATTRIBUTES = [
        'role',
        'profile_completed',
        'registration_status',
        'is_suspended',
        'name',
        'latitude',
        'longitude',
    ];

    public function saved(Model $model): void
    {
        if ($this->savedModelAffectsSearch($model)) {
            $this->bumpVersion();
        }
    }

    public function deleted(Model $model): void
    {
        if ($this->isRelevantModel($model)) {
            $this->bumpVersion();
        }
    }

    public function restored(Model $model): void
    {
        if ($this->isRelevantModel($model)) {
            $this->bumpVersion();
        }
    }

    private function savedModelAffectsSearch(Model $model): bool
    {
        if ($model instanceof User) {
            return $this->professionalUserChanged($model);
        }

        return $this->isRelevantModel($model);
    }

    private function isRelevantModel(Model $model): bool
    {
        if ($model instanceof User) {
            return $model->role === 'professional';
        }

        return $model instanceof Professional || $model instanceof Service;
    }

    private function professionalUserChanged(User $user): bool
    {
        if ($user->role !== 'professional') {
            return false;
        }

        return $user->wasRecentlyCreated || $user->wasChanged(self::RELEVANT_USER_ATTRIBUTES);
    }

    private function bumpVersion(): void
    {
        Cache::increment(ProfessionalSearchCache::VERSION_CACHE_KEY);
    }
}
