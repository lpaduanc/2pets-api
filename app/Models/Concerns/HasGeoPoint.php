<?php

namespace App\Models\Concerns;

use App\Services\Search\GeoLocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza uma coluna PostGIS `geography(POINT,4326)` a partir de colunas
 * decimais de latitude/longitude sempre que o model é salvo com uma delas
 * alterada.
 *
 * Eloquent não entende o tipo `geography`, então a escrita continua sendo SQL
 * cru — mas sempre via {@see GeoLocationService::makePointExpression()}, que
 * usa bindings (`?`) em vez de interpolar a coordenada na string, e sempre no
 * mesmo lugar, em vez de duplicada em cada controller que toca o endereço.
 *
 * Nomes de coluna são configuráveis por model porque o repo não é consistente
 * entre tabelas: `users`/`locations` usam `latitude`/`longitude`/`location`,
 * mas `lost_pet_alerts` usa `last_seen_latitude`/`last_seen_longitude`.
 * Sobrescreva os três métodos protegidos quando a tabela não usar o padrão.
 */
trait HasGeoPoint
{
    protected static function bootHasGeoPoint(): void
    {
        static::saved(function (self $model): void {
            $model->syncGeoPoint();
        });
    }

    /**
     * Recalcula a coluna geography quando latitude/longitude mudaram nesta
     * escrita. Se uma das duas foi ANULADA, a coluna também é limpa: sem
     * isso, um endereço novo que não geocodificou deixava o ponto antigo
     * valendo e o registro aparecia na busca no lugar errado. Num INSERT sem
     * coordenada não há o que limpar.
     *
     * `wasChanged()` sozinho não cobre o `create()`: o Eloquent só popula
     * `$this->changes` em `performUpdate()` — `performInsert()` nunca chama
     * `syncChanges()`, então logo após um INSERT `wasChanged()` responde
     * `false` para tudo, mesmo com o registro inteiro "novo". Por isso o
     * guard também aceita `wasRecentlyCreated`.
     */
    public function syncGeoPoint(): void
    {
        $latitudeColumn = $this->geoLatitudeColumn();
        $longitudeColumn = $this->geoLongitudeColumn();

        if (! $this->wasRecentlyCreated && ! $this->wasChanged([$latitudeColumn, $longitudeColumn])) {
            return;
        }

        $latitude = $this->{$latitudeColumn};
        $longitude = $this->{$longitudeColumn};

        if ($latitude !== null && $longitude !== null) {
            $this->writeGeoPoint((float) $latitude, (float) $longitude);

            return;
        }

        if ($this->wasChanged([$latitudeColumn, $longitudeColumn])) {
            $this->clearGeoPoint();
        }
    }

    private function clearGeoPoint(): void
    {
        DB::statement(
            sprintf('UPDATE %s SET %s = NULL WHERE id = ?', $this->getTable(), $this->geoLocationColumn()),
            [$this->getKey()]
        );
    }

    /**
     * Executa o UPDATE da coluna geography via SQL cru parametrizado.
     *
     * A falha aqui deixaria o registro fora da busca geográfica em silêncio
     * — por isso não é engolida: fica registrada com contexto (model, id,
     * coordenadas) e a exceção sobe para quem chamou save()/update(), para
     * que o cadastro/atualização de perfil que a originou também falhe em
     * vez de parecer bem-sucedido.
     */
    private function writeGeoPoint(float $latitude, float $longitude): void
    {
        $point = app(GeoLocationService::class)->makePointExpression($latitude, $longitude);

        try {
            DB::statement(
                sprintf(
                    'UPDATE %s SET %s = %s WHERE id = ?',
                    $this->getTable(),
                    $this->geoLocationColumn(),
                    $point['sql']
                ),
                [...$point['bindings'], $this->getKey()]
            );
        } catch (\Throwable $exception) {
            Log::error('HasGeoPoint: falha ao sincronizar coluna geografica', [
                'model' => static::class,
                'id' => $this->getKey(),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    protected function geoLatitudeColumn(): string
    {
        return 'latitude';
    }

    protected function geoLongitudeColumn(): string
    {
        return 'longitude';
    }

    protected function geoLocationColumn(): string
    {
        return 'location';
    }
}
