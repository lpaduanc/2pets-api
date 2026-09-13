<?php

namespace App\Http\Resources;

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
 * Decisão sobre latitude/longitude: ficam, mas arredondadas — `distance_km` já resolve
 * "perto de você" e o app usa a coordenada para plotar o pin no mapa da busca
 * (`SearchPage.vue`), então remover quebraria essa tela. Arredondar para 3 casas (~110m no
 * equador) tira a precisão de endereço exato — sensível sobretudo para o vet volante, que
 * pode operar de um endereço residencial — sem deixar o mapa impreciso a ponto de atrapalhar.
 *
 * Decisão sobre `address`: fica como está. É informação comercial pública para clínica e
 * petshop (endereço de loja/consultório, já indexado no Google Maps de qualquer forma), e
 * não existe hoje um campo que separe endereço residencial de comercial para tratar o vet
 * volante à parte — inventar essa distinção aqui seria decisão de produto, não bug fix.
 */
class PublicProfessionalSearchResource extends ProfessionalSearchResource
{
    private const COORDINATE_PRECISION = 3;

    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        unset($data['email'], $data['phone']);

        $data['latitude'] = $this->roundCoordinate($data['latitude']);
        $data['longitude'] = $this->roundCoordinate($data['longitude']);

        return $data;
    }

    private function roundCoordinate(mixed $coordinate): ?float
    {
        if ($coordinate === null) {
            return null;
        }

        return round((float) $coordinate, self::COORDINATE_PRECISION);
    }
}
