<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\PresentsPublicProfessionalLocation;
use Illuminate\Http\Request;

/**
 * Card de LISTAGEM: `GET /public/search`, `/public/nearby` e `/public/featured`.
 *
 * Existe porque o resource anterior servia listagem e página de perfil com o mesmo payload,
 * e a listagem pagava por dado que nenhum card mostra. Medido pelo frontend numa resposta
 * real de 24.623 bytes: só 4.719 bytes eram consumidos. O desperdício vinha de dois blocos:
 *
 * - `services[]` completo, com `description` de cada serviço — 10.277 bytes por resposta,
 *   ~4 serviços por profissional, e o card não exibe nenhum. O que ele usa da relação é
 *   UM número: `starting_price`.
 * - um bloco `professional{}` que repetia `bio`, `average_rating` e `total_reviews` já
 *   presentes na raiz — 6.350 bytes de duplicata pura.
 *
 * `ProfessionalSearchResource` (com `services[]`, CRMV, horários) continua servindo a
 * página de perfil (`GET /public/professionals/{id}`) e a lista de favoritos, que
 * realmente precisam disso. Conferido antes de cortar: nem o app (`SearchPage.vue`,
 * `useProfessionalSearch.js`) nem o site (`Marketplace.vue`) leem `services`, o bloco
 * `professional{}` ou `availability{}` a partir da busca.
 *
 * O eager load de `professional.services` PERMANECE na listagem, e isso é deliberado:
 * `starting_price` é o menor preço ativo do profissional e o card o exibe. São ~4 linhas
 * por profissional em uma única query para a página inteira (15 profissionais) — barato no
 * Postgres. O caro era serializar e trafegar, e isso este resource resolve.
 */
class ProfessionalSearchCardResource extends ProfessionalSearchResource
{
    // Endereço/coordenada/distância: regra de privacidade do volante em
    // `PresentsPublicProfessionalLocation` (compartilhada com o perfil público).
    use PresentsPublicProfessionalLocation;

    public function toArray(Request $request): array
    {
        $professional = $this->professional;

        return [
            ...$this->identity($professional),
            ...$this->publicLocation($professional),
            ...$this->reputation($professional),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(?object $professional): array
    {
        return [
            'id' => $this->id,
            'name' => $professional?->business_name ?: $this->name,
            'avatar_url' => $this->avatar_url ?? null,
            'bio' => $professional?->description,
            'professional_type' => $professional?->professional_type,
            'professional_type_label' => $professional?->professional_type?->label() ?? 'Profissional',
            // Especialidades ficam: o CLAUDE.md §4 define o card como "foto, nome, tipo,
            // especialidades, distância, avaliação média". É um array curto de strings.
            'specialties' => $professional?->specialties ?? [],
            // Fase 7 do fluxo de agendamento: especialidades da EQUIPE (só preenchido para
            // quem tem organização) — é o que deixa claro ao tutor POR QUE uma clínica
            // apareceu numa busca por especialidade que o dono não pratica pessoalmente
            // (ex.: clínica aparece em "cardiologia" porque um vet da equipe é
            // cardiologista). Vazio para conta unipessoal, nunca repete o que já está em
            // `specialties`.
            'team_specialties' => $this->teamOnlySpecialties($professional),
        ];
    }

    /**
     * @return list<string>
     */
    private function teamOnlySpecialties(?object $professional): array
    {
        return array_values(array_diff(
            $professional?->team_specialties ?? [],
            $professional?->specialties ?? [],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function reputation(?object $professional): array
    {
        $minPrice = $professional?->services?->where('active', true)->min('price');

        return [
            'average_rating' => (float) ($professional?->average_rating ?? 0),
            'reviews_count' => (int) ($professional?->total_reviews ?? 0),
            // Serviço gratuito (R$ 0,00) é preço informado, não ausência de preço.
            'starting_price' => $minPrice !== null ? (float) $minPrice : null,
            // Badge "verificado" só depois da aprovação manual do CRMV (CLAUDE.md §2).
            'verified' => (bool) ($professional?->is_crmv_verified ?? false),
            'is_featured' => (bool) ($professional?->is_featured ?? false),
            'is_open_now' => $this->isOpenNow($professional),
            // Fase 2 do fluxo de agendamento — mesmo campo/mesma regra de
            // `ProfessionalSearchResource`, ver o comentário lá.
            'team_size' => (int) ($this->team_size ?? 0),
            'has_team' => ((int) ($this->team_size ?? 0)) > 1,
        ];
    }
}
