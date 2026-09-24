<?php

namespace App\Enums;

/**
 * Categoria de um item do feed "Atividade Recente" da Início do tutor (`GET /api/dashboard/stats`,
 * campo `data.recentActivity[].type`). Cada valor mapeia para um ícone/estilo diferente no app.
 */
enum ActivityFeedType: string
{
    case ACCOUNT = 'account';
    case PET = 'pet';
    case HEALTH = 'health';
    case APPOINTMENT = 'appointment';
    case PURCHASE = 'purchase';
    case NOTIFICATION = 'notification';
}
