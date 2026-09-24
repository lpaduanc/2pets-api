<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\PresentsPublicProfessionalLocation;
use Illuminate\Http\Request;

/**
 * Card público (sem login) para `GET /public/search`, `/public/nearby` e `/public/featured`.
 *
 * O CLAUDE.md descreve a busca pública como card resumido — "foto, nome, tipo,
 * especialidades, distância, avaliação média" — com o contato liberado só depois do login
 * ("clicar no card → redirect para login/cadastro"). `ProfessionalSearchResource` (a base
 * daqui) inclui `email`/`phone` porque também serve `FavoriteController` (usuário já
 * autenticado, já favoritou o profissional) — misturar os dois contratos deixaria a base
 * inteira de profissionais raspável sem conta. Este resource existe só para remover, no
 * caminho público, o que a base não deveria ter exposto.
 *
 * Endereço, coordenada e distância seguem `PresentsPublicProfessionalLocation` — a mesma
 * regra dos cards: ponto fixo publica o endereço comercial; volante só bairro/cidade/UF,
 * coordenada com 2 casas e distância em degraus de 500 m (decisão de produto, 2026-09-24).
 */
class PublicProfessionalSearchResource extends ProfessionalSearchResource
{
    use PresentsPublicProfessionalLocation;

    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        unset($data['email'], $data['phone'], $data['distance_km']);

        return [...$data, ...$this->publicLocation($this->professional)];
    }
}
