<?php

namespace App\Contracts;

/**
 * "Estabelecimento" para efeito de configuração de sinal (Fase 6 do fluxo de agendamento)
 * — implementado por `Organization` (clínica/petshop com equipe) e por `Professional`
 * (autônomo, sem organização). `DepositConfigResolver` depende só deste contrato, nunca
 * verifica `instanceof Organization`/`instanceof Professional` diretamente — novo tipo de
 * estabelecimento no futuro só precisa implementar esta interface.
 */
interface HasDepositSettings
{
    public function depositEnabled(): bool;

    public function depositPercentage(): ?float;
}
