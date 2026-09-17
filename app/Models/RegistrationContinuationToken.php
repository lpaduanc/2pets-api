<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Credencial de uso único que leva uma conta NÃO reivindicada (`password IS NULL`) até a tela
 * de continuação de cadastro — ver `RegistrationContinuationTokenService` para emissão/consumo
 * e o comentário da migration para o porquê de ser tabela própria em vez de signed URL.
 */
class RegistrationContinuationToken extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'consumed_at',
        'invalidated_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLive(): bool
    {
        return $this->consumed_at === null
            && $this->invalidated_at === null
            && $this->expires_at->isFuture();
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now());
    }
}
