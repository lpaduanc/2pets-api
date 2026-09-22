<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\Service;
use App\Support\ServiceNameNormalizer;
use Illuminate\Support\Collection;

/**
 * "O que a clínica oferece", visto pelo tutor — item 3 da Fase 2 do fluxo de agendamento.
 *
 * `services.professional_id` é 1:1 com um profissional (cada vet cadastra seu próprio
 * serviço), mas a tela do estabelecimento não pode mostrar "Consulta Geral" três vezes só
 * porque três veterinários da equipe oferecem. Este catálogo agrupa por NOME normalizado
 * (minúsculo, sem espaço nas pontas) — é a única chave disponível hoje, já que não existe
 * um catálogo de serviço compartilhado entre profissionais da mesma organização. Faixa de
 * preço (`price_min`/`price_max`) aparece quando os profissionais cobram diferente pelo
 * "mesmo" serviço.
 *
 * ⚠️ Duas entradas com nomes escritos diferente ("Consulta Geral" vs "Consulta Clínica
 * Geral") viram DOIS itens do catálogo, mesmo sendo o mesmo ato clínico. Correção
 * estrutural seria um catálogo de serviço da organização com FK em `services` — fora do
 * escopo desta fase, proponha ao usuário se isso virar problema real.
 */
final class OrganizationServiceCatalog
{
    /**
     * @return list<array{name: string, category: string, duration_minutes: int, price_min: float, price_max: float, professional_ids: list<int>, service_ids: list<int>}>
     */
    public function catalogFor(Organization $organization): array
    {
        return Service::query()
            ->where('organization_id', $organization->id)
            ->where('active', true)
            ->get()
            ->groupBy(fn (Service $service): string => ServiceNameNormalizer::normalize($service->name))
            ->map(fn (Collection $group): array => $this->summarize($group))
            ->values()
            ->all();
    }

    /**
     * `service_ids` — achado escrevendo o teste de ponta a ponta da Fase 8: sem isto, o
     * item do catálogo (o que o app mostra no passo "escolher serviço") não tinha NENHUM
     * id de `Service` para passar ao próximo passo ("escolher profissional",
     * `GET .../team?service_id=`). O agrupamento existia, mas não linkava ao passo
     * seguinte — exatamente o que o agrupamento foi criado para fazer (Fase 2). Qualquer
     * id do grupo serve para o filtro de `team()` (ele já resolve por NOME, não por id
     * exato — ver `OrganizationTeamService`/`BookingService::professionalOffersService`),
     * então o primeiro/representativo já fecha o contrato.
     *
     * @return array{name: string, category: string, duration_minutes: int, price_min: float, price_max: float, professional_ids: list<int>, service_ids: list<int>}
     */
    private function summarize(Collection $group): array
    {
        $representative = $group->first();

        return [
            'name' => $representative->name,
            'category' => $representative->category,
            'duration_minutes' => (int) $representative->duration,
            'price_min' => (float) $group->min('price'),
            'price_max' => (float) $group->max('price'),
            'professional_ids' => $group->pluck('professional_id')->unique()->values()->all(),
            'service_ids' => $group->pluck('id')->values()->all(),
        ];
    }
}
