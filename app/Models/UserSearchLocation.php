<?php

namespace App\Models;

use App\Enums\Location\SearchLocationSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Última localização confirmada pelo usuário para a busca de profissionais (uma por usuário).
 * Ver `App\Services\Location\SearchLocationService`.
 */
class UserSearchLocation extends Model
{
    protected $fillable = [
        'user_id',
        'source',
        'label',
        'zip_code',
        'neighborhood',
        'city',
        'state',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'source' => SearchLocationSource::class,
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
