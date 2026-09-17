<?php

namespace App\Models;

use App\Enums\PetVetAccessOrigin;
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

    /**
     * Terminal: o tutor trocou o nível deste vínculo e a concessão passou para a linha
     * sucessora (`superseded_by_id`). Nunca deletamos nem reescrevemos o nível anterior —
     * é o que permite reconstruir "teve `read` de X até Y, `full` a partir de Y".
     */
    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * Relações necessárias para `PetVetAccessResource` renderizar as duas partes sem N+1.
     *
     * `veterinarian.professional` traz CRMV/UF/selo (o que o tutor usa para decidir) e
     * `*.media` traz o avatar — `getFirstMediaUrl()` sobre relação não carregada dispara uma
     * query por linha. Centralizado aqui porque são seis call sites; um deles esquecer a
     * relação some com o CRMV do payload em silêncio.
     *
     * @var list<string>
     */
    public const PARTICIPANT_RELATIONS = [
        'veterinarian.professional',
        'veterinarian.media',
        'grantor.media',
    ];

    protected $fillable = [
        'pet_id',
        'veterinarian_id',
        'granted_by',
        'origin',
        'access_level',
        'requested_access_level',
        'message',
        'granted_at',
        'revoked_at',
        'superseded_at',
        'superseded_by_id',
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
            'requested_access_level' => VetAccessLevel::class,
            'origin' => PetVetAccessOrigin::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'superseded_at' => 'datetime',
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

    /** Linha que assumiu a concessão quando o tutor trocou o nível deste vínculo. */
    public function successor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
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

    /**
     * Aceite do tutor. O nível vem SEMPRE de fora: `requested_access_level` é indicação do
     * vet e nunca vira concessão sozinho.
     */
    public function accept(VetAccessLevel $grantedLevel): void
    {
        $this->update([
            'status' => self::STATUS_ACCEPTED,
            'access_level' => $grantedLevel,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);
    }

    /**
     * Encerra este vínculo porque outro nível passou a valer para o mesmo par (pet, vet).
     * A janela em que o nível deste registro valeu fica preservada em
     * `granted_at` → `superseded_at`.
     */
    public function supersede(?self $successor = null): void
    {
        $this->update([
            'status' => self::STATUS_SUPERSEDED,
            'is_active' => false,
            'superseded_at' => now(),
            'superseded_by_id' => $successor?->id,
        ]);
    }

    /** Fecha a corrente de auditoria quando a sucessora só existe depois do `supersede()`. */
    public function linkSuccessor(self $successor): void
    {
        $this->update(['superseded_by_id' => $successor->id]);
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
    public static function factoryCreatePending(User $vet, Pet $pet, VetAccessLevel $requestedLevel = VetAccessLevel::READ): self
    {
        return self::create([
            'pet_id' => $pet->id,
            'veterinarian_id' => $vet->id,
            'granted_by' => $pet->user_id,
            'access_level' => null,
            'requested_access_level' => $requestedLevel,
            'status' => self::STATUS_PENDING,
            'requested_at' => now(),
            'is_active' => false,
        ]);
    }
}
