<?php

/**
 * Mensagens da precificação (docs/gap-petflow/PLANO.md, 1.4): simulação e regras de venda
 * (desconto máximo, venda abaixo do custo). Par completo em `lang/en_US/pricing.php` — chave nova
 * aqui precisa da mesma chave lá.
 */
return [
    'item_not_found' => 'Item não encontrado no catálogo desta clínica.',
    'single_price_target' => 'Informe só um entre preço, margem e markup.',
    'margin_below_hundred' => 'A margem precisa ser menor que 100%.',
    'max_discount_exceeded' => 'Desconto de :effective% em ":item" passa do máximo permitido de :max%.',
    'below_cost_forbidden' => '":item" está abaixo do custo (R$ :net por unidade, custo R$ :cost). Corrija o preço ou marque como brinde, cortesia ou retorno.',
    'line_discount_exceeds_gross' => 'O desconto de ":item" passa do valor da linha (R$ :gross). Para dar o item, marque como brinde, cortesia ou retorno.',
    'header_discount_exceeds' => 'O desconto da venda passa do limite (:limit). Para dar itens, marque-os como brinde, cortesia ou retorno.',
    'courtesy_locked' => 'Linha de brinde, cortesia ou retorno não muda preço nem desconto. Desmarque primeiro.',
    'courtesy_not_marked' => 'Esta linha não está marcada como brinde, cortesia ou retorno.',
    'courtesy_forbidden' => 'Você não tem permissão para marcar brinde, cortesia ou retorno nesta venda.',
    'courtesy_marked_log' => 'Linha marcada como brinde, cortesia ou retorno',
    'courtesy_unmarked_log' => 'Marcação de brinde, cortesia ou retorno removida',
    'finalize_balance_due' => 'A venda ainda tem saldo a receber (R$ :due). Registre o recebimento.',
    'fiscal_courtesy_only' => 'Venda só com brinde, cortesia ou retorno não emite documento fiscal.',
];
