<?php

namespace App\Http\Resources\Commercial;

use App\Enums\SoldPackageSessionStatus;
use App\Models\SoldPackage;
use App\Models\SoldPackageSession;
use App\Services\Commercial\Package\SoldPackageInsights;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SoldPackage
 */
class SoldPackageResource extends JsonResource
{
    public const RESOURCE_RELATIONS = [
        'servicePackage', 'client:id,name', 'pet:id,name', 'items.service:id,name',
        // PLANO 2.7: sessões (valor congelado), baixas, agendamentos marcados, limiar "À vencer".
        'sessions', 'consumptions.appointment', 'appointments', 'organization:id,package_expiring_soon_days',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->effectiveStatus();
        $lifecycle = $this->lifecycleStatus($this->organization?->package_expiring_soon_days ?? 30);
        $insights = app(SoldPackageInsights::class);

        return [
            'id' => $this->id,
            'service_package' => $this->whenLoaded('servicePackage', fn () => [
                'id' => $this->servicePackage->id,
                'name' => $this->servicePackage->name,
            ]),
            'client' => $this->whenLoaded('client', fn () => ['id' => $this->client->id, 'name' => $this->client->name]),
            'pet' => $this->whenLoaded('pet', fn () => ['id' => $this->pet->id, 'name' => $this->pet->name]),

            'sold_at' => $this->sold_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toDateString(),

            'status' => $status->value,
            'status_label' => $status->label(),
            // PLANO 2.7: status da tela (Novo · Em uso · À vencer · Vencido · Completo · Cancelado).
            'lifecycle_status' => $lifecycle->value,
            'lifecycle_status_label' => $lifecycle->label(),
            'total_remaining' => $this->totalRemaining(),
            'times_used' => (int) $this->items->sum('quantity_used'),

            // Valores congelados na venda (PLANO 2.7).
            'package_price' => $this->package_price === null ? null : (string) $this->package_price,
            'list_price_total' => $this->list_price_total === null ? null : (string) $this->list_price_total,
            'discount_amount' => $this->discountAmount(),
            'used_amount' => $this->whenLoaded('sessions', fn () => $this->sumSessions(SoldPackageSessionStatus::CONSUMED)),
            'deferred_amount' => $this->whenLoaded('sessions', fn () => $this->sumSessions(SoldPackageSessionStatus::PENDING)),
            'expiration' => $this->expiration_resolved_at === null ? null : [
                'resolution' => $this->expiration_resolution?->value,
                'resolution_label' => $this->expiration_resolution?->label(),
                'resolved_at' => $this->expiration_resolved_at->toIso8601String(),
                'amount' => (string) $this->expiration_amount,
                'client_account_entry_id' => $this->client_account_entry_id,
            ],

            'average_return_days' => $this->whenLoaded('consumptions', fn () => $insights->averageReturnDays($this->resource)),
            'next_appointment' => $this->when($this->relationLoaded('appointments'), fn () => $insights->nextAppointment($this->resource)),
            'last_appointment' => $this->when($this->relationLoaded('appointments') && $this->relationLoaded('consumptions'), fn () => $insights->lastAppointment($this->resource)),

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'service_id' => $item->service_id,
                'service_name' => $item->service?->name,
                'quantity_total' => $item->quantity_total,
                'quantity_used' => $item->quantity_used,
                'quantity_remaining' => $item->quantityRemaining(),
                'sessions' => $this->relationLoaded('sessions')
                    ? $this->sessions->where('sold_package_item_id', $item->id)->values()->map(fn (SoldPackageSession $session): array => [
                        'id' => $session->id,
                        'sequence' => $session->sequence,
                        'amount' => (string) $session->amount,
                        'status' => $session->status->value,
                        'status_label' => $session->status->label(),
                        'consumed_at' => $session->consumed_at?->toIso8601String(),
                        'staff_id' => $session->staff_id,
                    ])->all()
                    : [],
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function discountAmount(): ?string
    {
        if ($this->package_price === null || $this->list_price_total === null) {
            return null;
        }

        return Money::of($this->list_price_total)->minus($this->package_price)->toString();
    }

    private function sumSessions(SoldPackageSessionStatus $status): string
    {
        return Money::sum($this->sessions->where('status', $status)->pluck('amount'))->toString();
    }
}
