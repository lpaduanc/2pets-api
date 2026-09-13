<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Convite por e-mail para ocupar um cargo (`OrganizationRole`) dentro de uma organização.
 * Token opaco em texto puro — mesmo padrão de `users.email_verification_token`
 * (`EmailVerificationController::verify`), não o hash+compare de `password_reset_tokens`:
 * é um link de uso único de validade curta, não uma credencial reutilizável.
 */
class OrganizationInvitation extends Model
{
    use HasFactory;

    public const DEFAULT_EXPIRATION_DAYS = 7;

    private const TOKEN_LENGTH = 40;

    protected $fillable = [
        'organization_id',
        'email',
        'role',
        'token',
        'invited_by',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            $invitation->token ??= Str::random(self::TOKEN_LENGTH);
            $invitation->expires_at ??= now()->addDays(self::DEFAULT_EXPIRATION_DAYS);
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * "Pendente" = ainda pode ser aceito: nem aceito, nem revogado, nem expirado.
     *
     * @param  Builder<OrganizationInvitation>  $query
     * @return Builder<OrganizationInvitation>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>=', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }
}
