<?php

namespace App\Models;

use App\Models\Concerns\HasGeoPoint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LostPetAlert extends Model
{
    use HasGeoPoint;

    protected $fillable = [
        'pet_id',
        'user_id',
        'status',
        'description',
        'last_seen_location',
        'last_seen_latitude',
        'last_seen_longitude',
        'alert_radius_km',
        'last_seen_at',
        'contact_info',
        'photos',
        'microchip_number',
        'reward_amount',
        'found_at',
        'found_details',
        'views_count',
        'shares_count',
    ];

    protected $casts = [
        'last_seen_latitude' => 'decimal:7',
        'last_seen_longitude' => 'decimal:7',
        'alert_radius_km' => 'decimal:2',
        'last_seen_at' => 'datetime',
        'contact_info' => 'array',
        'photos' => 'array',
        'reward_amount' => 'decimal:2',
        'found_at' => 'datetime',
        'views_count' => 'integer',
        'shares_count' => 'integer',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(FoundPetReport::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(LostPetNotification::class);
    }

    public function markAsFound(string $details): void
    {
        $this->update([
            'status' => 'found',
            'found_at' => now(),
            'found_details' => $details,
        ]);

        // `lost_alert_message` sai junto: a carteirinha publica so o exibe
        // enquanto `is_lost`, mas deixar o texto do sumico gravado num pet que
        // ja voltou e um resto de estado que reaparece no proximo alerta.
        $this->pet->update([
            'is_lost' => false,
            'lost_since' => null,
            'lost_alert_message' => null,
        ]);
    }

    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);

        $this->pet->update([
            'is_lost' => false,
            'lost_since' => null,
            'lost_alert_message' => null,
        ]);
    }

    public function incrementViews(): void
    {
        $this->increment('views_count');
    }

    public function incrementShares(): void
    {
        $this->increment('shares_count');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getDaysLost(): int
    {
        return $this->last_seen_at->diffInDays(now());
    }

    /**
     * `lost_pet_alerts` usa nomes de coluna diferentes de `users`/`locations`
     * (padrao do `HasGeoPoint`). A coluna geografica se chama `last_seen_geo`,
     * nao `last_seen_location` — esse nome ja e ocupado por uma coluna varchar
     * existente (o endereco textual digitado pelo tutor).
     */
    protected function geoLatitudeColumn(): string
    {
        return 'last_seen_latitude';
    }

    protected function geoLongitudeColumn(): string
    {
        return 'last_seen_longitude';
    }

    protected function geoLocationColumn(): string
    {
        return 'last_seen_geo';
    }
}
