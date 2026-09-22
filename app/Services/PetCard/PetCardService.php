<?php

namespace App\Services\PetCard;

use App\Models\LostPetAlert;
use App\Models\Pet;
use App\Models\User;
use App\Services\LostPet\LostPetAlertService;

final class PetCardService
{
    public function __construct(
        private readonly LostPetAlertService $lostPetAlertService
    ) {}

    /**
     * Marca o pet como perdido e abre o `LostPetAlert` que dispara a
     * notificacao para todo tutor e todo profissional dentro do raio.
     *
     * Ate aqui este metodo so escrevia `is_lost` no pet e deixava um
     * `// TODO: Notify nearby professionals about lost pet` — ou seja, o unico
     * caminho de producao para "meu pet sumiu" (o botao da carteirinha em
     * `PetCardPage.vue`) nunca notificou ninguem: `LostPetAlertService` e o job
     * `NotifyNearbyUsersOfLostPetAlert` existiam, mas nenhuma rota os chamava.
     *
     * A coordenada do alerta e, em ordem: a que o app mandou (o tutor pode
     * perceber o sumico longe de casa), senao o endereco de cadastro do tutor.
     * Sem nenhuma das duas o pet ainda fica marcado como perdido — a
     * carteirinha publica passa a mostrar o aviso —, mas nao ha raio para
     * notificar, e `LostPetAlertService` simplesmente nao despacha o job.
     */
    public function markAsLost(Pet $pet, array $data = []): LostPetAlert
    {
        $tutor = $pet->user;

        if ($existing = $this->activeAlertFor($pet)) {
            return $existing;
        }

        return $this->lostPetAlertService->createAlert($pet, [
            'description' => $data['message'] ?? $this->defaultDescription($pet),
            'last_seen_location' => $data['last_seen_location'] ?? $this->tutorAddress($tutor),
            'last_seen_latitude' => $data['last_seen_latitude'] ?? $tutor?->latitude,
            'last_seen_longitude' => $data['last_seen_longitude'] ?? $tutor?->longitude,
            'alert_radius_km' => $data['alert_radius_km'] ?? config('lost-pet.default_radius_km'),
            'last_seen_at' => $data['last_seen_at'] ?? now(),
            'contact_info' => $this->contactInfo($tutor),
        ]);
    }

    /**
     * Fecha o alerta junto com o pet: um alerta `active` de um pet que ja
     * voltou continua aparecendo em `getNearbyAlerts()` e na busca por
     * microchip para sempre.
     */
    public function markAsFound(Pet $pet): void
    {
        $alert = $this->activeAlertFor($pet);

        if ($alert !== null) {
            $alert->markAsFound('Tutor marcou o pet como encontrado pela carteirinha.');

            return;
        }

        $pet->update([
            'is_lost' => false,
            'lost_alert_message' => null,
            'lost_since' => null,
        ]);
    }

    /**
     * Reabrir o mesmo alerta em vez de empilhar um novo: sem isso, cada toque
     * no botao criaria outro `LostPetAlert` `active` e re-notificaria todo o
     * raio de 30 km.
     */
    private function activeAlertFor(Pet $pet): ?LostPetAlert
    {
        return LostPetAlert::where('pet_id', $pet->id)
            ->where('status', 'active')
            ->first();
    }

    private function defaultDescription(Pet $pet): string
    {
        return "{$pet->name} está perdido. Se você vir este pet, entre em contato com o tutor.";
    }

    private function tutorAddress(?User $tutor): string
    {
        $parts = array_filter([
            $tutor?->neighborhood,
            $tutor?->city,
            $tutor?->state,
        ]);

        return $parts === [] ? 'Localização não informada' : implode(', ', $parts);
    }

    /**
     * @return array{name: string, phone: string|null}
     */
    private function contactInfo(?User $tutor): array
    {
        return [
            'name' => $tutor?->name ?? '',
            'phone' => $tutor?->phone,
        ];
    }
}
