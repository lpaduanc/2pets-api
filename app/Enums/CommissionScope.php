<?php

namespace App\Enums;

/**
 * `commission_rules.scope` — contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md, regra de negócio 2.
 *
 * Hierarquia de resolução (mais específico vence): `product`/`service` > `product_group` >
 * `all`. `specialty` fica reservado no enum (schema completo, coerente com o CHECK do banco)
 * mas sem resolução própria nesta rodada — a plataforma ainda não amarra especialidade a
 * `organization_members` de um jeito que dê pra resolver sem ambiguidade; ver o comentário de
 * `CommissionRuleResolver`.
 */
enum CommissionScope: string
{
    case ALL = 'all';
    case PRODUCT_GROUP = 'product_group';
    case SERVICE = 'service';
    case PRODUCT = 'product';
    case SPECIALTY = 'specialty';

    public function label(): string
    {
        return match ($this) {
            self::ALL => 'Geral',
            self::PRODUCT_GROUP => 'Grupo de produto/serviço',
            self::SERVICE => 'Serviço específico',
            self::PRODUCT => 'Produto específico',
            self::SPECIALTY => 'Especialidade',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
