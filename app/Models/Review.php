<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use SoftDeletes;

    public const MODERATION_PENDING = 'pending';

    public const MODERATION_APPROVED = 'approved';

    public const MODERATION_REJECTED = 'rejected';

    public const MODERATION_FLAGGED = 'flagged';

    protected $fillable = [
        'professional_id',
        'client_id',
        'appointment_id',
        'rating',
        'comment',
        'is_verified',
        'is_visible',
        'is_flagged',
        'flag_reason',
        'helpful_count',
        'moderation_status',
        'moderation_note',
        'moderated_by',
        'moderated_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_verified' => 'boolean',
        'is_visible' => 'boolean',
        'is_flagged' => 'boolean',
        'helpful_count' => 'integer',
        'moderated_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function response(): HasOne
    {
        return $this->hasOne(ReviewResponse::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ReviewPhoto::class);
    }

    public function helpfulVotes(): HasMany
    {
        return $this->hasMany(ReviewHelpfulVote::class);
    }

    public function hasResponse(): bool
    {
        return $this->response()->exists();
    }

    public function isVerified(): bool
    {
        return $this->is_verified;
    }

    /**
     * Helper de teste — cria review pendente de moderação para um profissional.
     */
    public static function factoryPending(int $professionalId): self
    {
        $client = User::factory()->tutor()->create();

        return self::create([
            'professional_id' => $professionalId,
            'client_id' => $client->id,
            'rating' => 5,
            'comment' => 'Teste',
            'is_verified' => false,
            'is_visible' => false,
            'moderation_status' => self::MODERATION_PENDING,
        ]);
    }
}
