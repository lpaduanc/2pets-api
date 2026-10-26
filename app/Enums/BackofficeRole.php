<?php

namespace App\Enums;

/**
 * Papel de quem trabalha no 2pets Admin. Cada valor é um papel Spatie de mesmo nome; quem tem
 * qualquer um deles é "equipe" e grava `users.role = staff` — nem o app do tutor nem o 2pets Pro
 * aceitam esse valor (ver `APP_ROLES` dos dois frontends), então a conta só serve aqui.
 *
 * `admin` e `super_admin` já existiam no seeder e continuam passando no `AdminMiddleware`
 * legado (onde mora o catálogo). `support` é novo e não passa nele de propósito.
 */
enum BackofficeRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case ADMIN = 'admin';
    case SUPPORT = 'support';

    public const USERS_ROLE_COLUMN = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super admin',
            self::ADMIN => 'Administrador',
            self::SUPPORT => 'Suporte',
        };
    }

    /**
     * Pacote que a tela de equipe pré-marca ao escolher o papel.
     *
     * @return list<string>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::SUPER_ADMIN => BackofficePermission::values(),
            self::ADMIN => array_values(array_diff(BackofficePermission::values(), [BackofficePermission::TEAM_MANAGE->value])),
            self::SUPPORT => [
                BackofficePermission::USERS_VIEW->value,
                BackofficePermission::USERS_CREATE->value,
                BackofficePermission::IMPERSONATE->value,
            ],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value: string, label: string, permissions: list<string>}> */
    public static function options(): array
    {
        return array_map(
            fn (self $r) => ['value' => $r->value, 'label' => $r->label(), 'permissions' => $r->defaultPermissions()],
            self::cases(),
        );
    }
}
