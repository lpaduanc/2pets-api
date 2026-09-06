<?php

namespace App\Models;

use App\Enums\VetAccessLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PetVetAccess extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'pet_id',
        'veterinarian_id',
        'granted_by',
        'access_level',
        'granted_at',
        'revoked_at',
        'is_active',
        'status',
        'requested_at',
        'responded_at',
        'rejection_reason',
        'revocation_reason',
        'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'access_level' => VetAccessLevel::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function veterinarian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'veterinarian_id');
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    public function scopeActive($query)
    {
        // "Active" now means accepted and not revoked — pending requests have no access.
        return $query->where('status', self::STATUS_ACCEPTED)
            ->where('is_active', true)
            ->whereNull('revoked_at');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForVet($query, int $veterinarianId)
    {
        return $query->where('veterinarian_id', $veterinarianId);
    }

    public function scopeForPet($query, int $petId)
    {
        return $query->where('pet_id', $petId);
    }

    // ──────────────────────────────────────────────
    // Methods
    // ──────────────────────────────────────────────

    public function revoke(?int $revokedBy = null, ?string $reason = null): void
    {
        $this->update([
            'is_active' => false,
            'revoked_at' => now(),
            'status' => self::STATUS_REVOKED,
            'revocation_reason' => $reason,
            'revoked_by' => $revokedBy,
        ]);
    }

    public function accept(): void
    {
        $this->update([
            'status' => self::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);
    }

    public function reject(?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'is_active' => false,
            'responded_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Helper de teste — cria acesso pendente com o par vet+pet passados.
     * Em produção isso vem pelo endpoint /pet-vet-access/request.
     */
    public static function factoryCreatePending(User $vet, Pet $pet): self
    {
        return self::create([
            'pet_id' => $pet->id,
            'veterinarian_id' => $vet->id,
            'granted_by' => $pet->user_id,
            'access_level' => \App\Enums\VetAccessLevel::READ,
            'status' => self::STATUS_PENDING,
            'requested_at' => now(),
            'is_active' => false,
        ]);
    }
}
