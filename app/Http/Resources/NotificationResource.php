<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Achata o `DatabaseNotification` cru do Laravel.
 *
 * Sem este Resource, `GET /notifications` devolvia o model do pacote de notificações
 * diretamente: o payload útil (`title`, `message`, `action_url`) vinha aninhado dentro da
 * coluna `data`, e `type` era o FQCN da classe de notificação
 * (`App\Notifications\PetVetAccessRequested`) em vez do tipo semântico que o próprio
 * `toArray()` de cada notificação já grava (`pet_vet_access_requested`).
 *
 * `data` aqui é o que sobra do payload original depois de extrair os campos padrão — os
 * detalhes específicos de cada tipo de notificação (`access_id`, `pet_id`, `granted_access_level`...).
 *
 * `message` cai para `body` por compatibilidade com linhas legadas: `InAppNotification`
 * gravava a mensagem em `body` até 2026-09-13, e essas linhas já existem no banco de produção.
 * Todo write path novo usa `message` — não adicione um terceiro nome.
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payload = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            'type' => $payload['type'] ?? class_basename($this->type),
            'title' => $payload['title'] ?? null,
            'message' => $payload['message'] ?? $payload['body'] ?? null,
            'action_url' => $payload['action_url'] ?? null,
            'data' => $payload['data'] ?? [],
            'read_at' => $this->read_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
