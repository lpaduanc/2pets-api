<?php

namespace App\Services\Booking;

use App\Models\BlockedTime;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Support\Collection;

/**
 * CRUD de bloqueios de agenda (`blocked_times`) — férias, almoço, compromisso. Mesma
 * regra de posse do resto do grupo COMERCIAL (`CommercialScopeResolver::ownershipFor()`).
 */
final class BlockedTimeManagementService
{
    public function __construct(private readonly CommercialScopeResolver $scopeResolver) {}

    /**
     * @param  array{location_id: int|null, start_datetime: string, end_datetime: string, reason?: string|null}  $data
     */
    public function create(User $targetProfessional, array $data): BlockedTime
    {
        return BlockedTime::create([
            ...$data,
            ...$this->scopeResolver->ownershipFor($targetProfessional),
        ]);
    }

    /**
     * @param  array{location_id: int|null, start_datetime: string, end_datetime: string, reason?: string|null}  $data
     */
    public function update(BlockedTime $blockedTime, array $data): BlockedTime
    {
        $blockedTime->update($data);

        return $blockedTime;
    }

    public function delete(BlockedTime $blockedTime, User $deletedBy): void
    {
        $blockedTime->update(['deleted_by' => $deletedBy->id]);
        $blockedTime->delete();
    }

    /**
     * Histórico de bloqueios removidos (item 21) — inclui soft-deleted, quem removeu e
     * quando. Só os removidos, mais recentes primeiro: quem quer ver os ativos usa `index()`.
     *
     * @return Collection<int, BlockedTime>
     */
    public function history(User $targetProfessional, ?int $locationId): Collection
    {
        return BlockedTime::onlyTrashed()
            ->where('professional_id', $targetProfessional->id)
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->with('deletedBy:id,name')
            ->orderByDesc('deleted_at')
            ->get();
    }
}
