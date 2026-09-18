<?php

namespace App\Contracts;

/**
 * Tudo que pode virar uma linha de `sale_items`: hoje `Product` e `Service`.
 *
 * Existe para que `SaleService` nunca precise perguntar "isto é produto ou serviço?" antes de
 * montar um item — a pergunta do balcão é sempre a mesma (quanto custa, posso mudar o preço,
 * quanto de comissão, baixa estoque?) e a resposta é que muda. Sem esta interface, cada regra
 * nova do PDV vira um `instanceof` a mais espalhado pelo service (violação de OCP; ver
 * "Padrões de Código" do backend-specialist).
 *
 * Contrato de docs/gap-simplesvet/08-produtos-precificacao-lista-precos.md, seção "Decisão de
 * modelagem": tabelas separadas (NCM × LC116), interface comum.
 */
interface Sellable
{
    public function getKey();

    /** Descrição que vai impressa na linha da venda. */
    public function sellableName(): string;

    /** Preço sugerido no balcão, antes de qualquer desconto. */
    public function sellableUnitPrice(): float;

    /**
     * `false` bloqueia preço diferente do cadastrado — critério de aceite do doc 08
     * ("rejeita preço diferente no PDV (422)").
     */
    public function allowsPriceOverride(): bool;

    /** Percentual de comissão do item, base do doc 09. `null` = cai na regra geral. */
    public function commissionPercent(): ?float;

    /** `false` para serviço e para produto com `controls_stock = false` (doc 07). */
    public function movesStock(): bool;

    /** Custo unitário usado na base de comissão `margin` (doc 09). */
    public function unitCost(): float;

    public function appearsInPriceList(): bool;
}
