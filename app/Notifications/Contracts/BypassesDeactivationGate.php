<?php

namespace App\Notifications\Contracts;

/**
 * Marca uma Notification que deve ser entregue mesmo quando o destinatário está com a conta
 * desativada — hoje, nenhuma. É o contrato que separa comunicação operacional (lembrete de
 * vacina, consulta, mensagem — para de chegar assim que a conta é desativada, ver
 * `User::notify()`) da campanha de reativação (deve continuar chegando, respeitando
 * `marketing_consent`). Quando a campanha ganhar uma Notification própria, ela implementa esta
 * interface; até lá o portão fica fechado por padrão para qualquer tipo novo.
 */
interface BypassesDeactivationGate {}
