<?php

namespace App\Enums;

/**
 * Cor semântica de um item do feed "Atividade Recente" (`data.recentActivity[].tone`) — decide
 * o estilo visual do card no app (verde para sucesso, vermelho para erro etc.).
 */
enum ActivityFeedTone: string
{
    case PRIMARY = 'primary';
    case SUCCESS = 'success';
    case WARNING = 'warning';
    case ERROR = 'error';
    case INFO = 'info';
}
