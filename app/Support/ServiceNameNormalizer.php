<?php

namespace App\Support;

/**
 * "Mesmo serviço" entre profissionais de uma equipe é decidido por NOME normalizado
 * (minúsculo, sem espaço nas pontas) — não existe catálogo de serviço compartilhado com FK
 * neste projeto (ver docblock de `App\Services\Organization\OrganizationServiceCatalog`).
 *
 * Extraído para cá porque DOIS lugares precisam do MESMO critério e não podiam discordar
 * entre si (achado real, Fase 6): `OrganizationServiceCatalog`/`OrganizationTeamService`
 * agrupam/filtram por nome, mas `BookingService::assertProfessionalBelongsToOrganization()`
 * comparava por `professional_id` exato — numa clínica onde dois vets cadastram "Consulta
 * Geral" com nomes iguais e ids diferentes (exatamente o caso que o catálogo agrupado
 * existe para cobrir), o agendamento por equipe rejeitava um profissional que legitimamente
 * oferecia o serviço. Os dois consumidores agora chamam esta mesma função — não podem
 * mais divergir silenciosamente.
 */
final class ServiceNameNormalizer
{
    public static function normalize(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
