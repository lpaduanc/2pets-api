<?php

namespace App\Enums;

/**
 * Permissões do 2pets Admin (backoffice). São permissões Spatie comuns (guard `web`), dadas
 * DIRETAMENTE a cada membro da equipe — o papel (`BackofficeRole`) só define o pacote padrão
 * que a tela de equipe pré-marca. `super_admin` passa em todas sem precisar delas.
 *
 * `impersonation.manage` reaproveita a permissão que já existia no seeder (item 22).
 */
enum BackofficePermission: string
{
    case USERS_VIEW = 'backoffice.users.view';
    case USERS_MANAGE = 'backoffice.users.manage';
    case USERS_CREATE = 'backoffice.users.create';
    case IMPERSONATE = 'impersonation.manage';
    case CATALOG_MANAGE = 'backoffice.catalog.manage';
    case TEAM_MANAGE = 'backoffice.team.manage';

    public function label(): string
    {
        return match ($this) {
            self::USERS_VIEW => 'Ver tutores e profissionais',
            self::USERS_MANAGE => 'Editar, suspender e reativar contas',
            self::USERS_CREATE => 'Cadastrar tutores e profissionais',
            self::IMPERSONATE => 'Acessar como o usuário',
            self::CATALOG_MANAGE => 'Gerenciar catálogo de produtos',
            self::TEAM_MANAGE => 'Gerenciar a equipe do admin',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $p) => ['value' => $p->value, 'label' => $p->label()],
            self::cases(),
        );
    }
}
