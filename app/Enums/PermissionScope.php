<?php

namespace App\Enums;

/**
 * Escopo de uma permissão granular (`recurso.ação.escopo`) — item 22 do backlog
 * gap-simplesvet. O projeto usa deliberadamente `own`/`any`, nunca `company`/`all`
 * (ver `docs/gap-simplesvet/specs/permissoes-catalogo.md`): não é suporte a "times"
 * do Spatie, é só o sufixo do nome da permissão.
 *
 * Uso: documentar/tipar o escopo em Services e Policies que precisam decidir entre
 * devolver só o registro do próprio usuário ou qualquer registro da organização —
 * a resolução em si continua no Service/Policy do recurso, este enum não decide nada
 * sozinho.
 */
enum PermissionScope: string
{
    case OWN = 'own';
    case ANY = 'any';

    /**
     * Nome de permissão Spatie para este escopo, dado o prefixo do recurso.
     * Ex.: `financial.view.own` a partir de `financial.view`.
     */
    public function permissionName(string $resourceAction): string
    {
        return "{$resourceAction}.{$this->value}";
    }
}
